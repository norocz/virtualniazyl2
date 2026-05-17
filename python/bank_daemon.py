#!/usr/bin/env python3
"""
Virtuální Azyl — bank daemon
============================

Běží trvale na pozadí, každých 30 vteřin:
1. Stáhne nové bankovní transakce z Fio banky (token-based API)
2. Pošle je do PHP backendu přes /shop-api/payments-in
3. Backend je spáruje s objednávkami a vytvoří payouts/refunds

Požadavky:
    pip install requests python-dotenv pydantic

Konfigurace:
    Vytvořte soubor .env vedle tohoto skriptu:

        FIO_API_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
        BACKEND_URL=https://virtualniazyl.cz/shop-api
        BACKEND_API_KEY=randomSecretKey
        POLL_INTERVAL=30
        LOG_FILE=/var/log/vaz-bankdaemon.log

Spuštění (systemd unit doporučeno):
    python3 bank_daemon.py

Autor: tvůrce projektu Virtuální Azyl.
"""

from __future__ import annotations

import json
import logging
import os
import signal
import sys
import time
from datetime import datetime, timezone
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Any

import requests
from dotenv import load_dotenv


load_dotenv(Path(__file__).parent / ".env")

# ----------------------- Konfigurace -----------------------
FIO_API_TOKEN = os.getenv("FIO_API_TOKEN", "").strip()
FIO_API_BASE = "https://fioapi.fio.cz/v1/rest"
BACKEND_URL = os.getenv("BACKEND_URL", "http://localhost/shop-api").rstrip("/")
BACKEND_API_KEY = os.getenv("BACKEND_API_KEY", "").strip()
POLL_INTERVAL = int(os.getenv("POLL_INTERVAL", "30"))
LOG_FILE = os.getenv("LOG_FILE", "").strip() or None
HEARTBEAT_EVERY_N_POLLS = 10   # každých 5 minut při 30s intervalu

# ----------------------- Logging -----------------------
logger = logging.getLogger("vaz-bank-daemon")
logger.setLevel(logging.INFO)
_fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s")

_stream = logging.StreamHandler()
_stream.setFormatter(_fmt)
logger.addHandler(_stream)

if LOG_FILE:
    _file = RotatingFileHandler(LOG_FILE, maxBytes=5_000_000, backupCount=5)
    _file.setFormatter(_fmt)
    logger.addHandler(_file)


# ----------------------- Signály (graceful shutdown) -----------------------
_running = True


def _handle_sigterm(signum, frame):  # noqa: ARG001
    global _running
    logger.info("Signál %s přijat, končím smyčku.", signum)
    _running = False


signal.signal(signal.SIGTERM, _handle_sigterm)
signal.signal(signal.SIGINT, _handle_sigterm)


# ----------------------- Fio API -----------------------
class FioApiError(Exception):
    """Obecná chyba při komunikaci s Fio API."""


def fetch_new_transactions() -> list[dict[str, Any]]:
    """
    Stáhne nové transakce od posledního stažení (Fio si interně pamatuje kurzor).
    Endpoint `last/{token}/transactions.json` vrací jen nové transakce.

    Dokumentace: https://www.fio.cz/docs/cz/API_Bankovnictvi.pdf
    """
    if not FIO_API_TOKEN:
        logger.warning("FIO_API_TOKEN není nastaven, přeskakuji stahování.")
        return []

    url = f"{FIO_API_BASE}/last/{FIO_API_TOKEN}/transactions.json"
    try:
        resp = requests.get(url, timeout=20)
    except requests.RequestException as e:
        raise FioApiError(f"Chyba sítě při volání Fio API: {e}") from e

    if resp.status_code == 409:
        # Fio vrací 409 když se volá moc často (má rate limit)
        logger.warning("Fio API rate limit (409), zkusím příště.")
        return []
    if resp.status_code != 200:
        raise FioApiError(f"Fio API vrátila HTTP {resp.status_code}: {resp.text[:200]}")

    try:
        data = resp.json()
    except ValueError as e:
        raise FioApiError(f"Neplatný JSON z Fio: {e}") from e

    account_info = data.get("accountStatement", {}).get("info", {})
    account_id = account_info.get("accountId", "unknown")
    transactions = data.get("accountStatement", {}).get("transactionList", {}).get("transaction", [])

    logger.info("Staženo %d nových transakcí (účet %s).", len(transactions), account_id)

    # Fio vrací transakce v divném formátu se column_XX. Normalizujeme.
    normalized = [_normalize_fio_transaction(tx, account_id) for tx in transactions]
    # Filtr - zajímají nás jen příchozí (objem > 0)
    return [tx for tx in normalized if tx["objem"] > 0]


def _normalize_fio_transaction(tx: dict[str, Any], account_id: str) -> dict[str, Any]:
    """
    Fio vrací data ve struktuře:
      tx = { "column_22": {"value": "123", "name": "ID pohybu", "id": 22}, ... }

    Mapování sloupců (viz Fio dokumentace):
      column_0  = datum
      column_1  = objem
      column_2  = protiúčet
      column_3  = kód banky
      column_4  = KS
      column_5  = VS
      column_6  = SS
      column_7  = specific
      column_8  = operace type
      column_9  = executed by
      column_10 = name
      column_12 = recipient message
      column_14 = details
      column_16 = payer reference
      column_17 = comment
      column_18 = BIC
      column_22 = ID (globálně unikátní bank transaction id)
      column_25 = bank name
    """
    def col(name: str) -> Any:
        entry = tx.get(name)
        return entry.get("value") if isinstance(entry, dict) else None

    objem = col("column_1")
    try:
        objem = float(objem) if objem is not None else 0.0
    except (ValueError, TypeError):
        objem = 0.0

    return {
        "id": str(col("column_22") or ""),
        "account_id": account_id,
        "datum": col("column_0"),    # už je v ISO 8601
        "objem": objem,
        "protiucet": str(col("column_2") or ""),
        "nazev_protiuctu": str(col("column_10") or ""),
        "kod_banky": str(col("column_3") or ""),
        "bank_name": col("column_25"),
        "ks": col("column_4"),
        "vs": str(col("column_5")) if col("column_5") else None,
        "ss": col("column_6"),
        "user_identification": None,
        "recipient_message": col("column_12"),
        "operation_type": str(col("column_8") or "unknown"),
        "executed_by": col("column_9"),
        "details": col("column_14"),
        "comment": col("column_17"),
        "bic": col("column_18"),
        "payer_reference": col("column_16"),
    }


# ----------------------- Backend API -----------------------
class BackendApiError(Exception):
    """Chyba při volání PHP backendu."""


def push_payments_to_backend(payments: list[dict[str, Any]]) -> dict[str, Any]:
    """POST /shop-api/payments-in"""
    if not payments:
        return {"received": 0, "matched": 0, "already_exists": 0}

    url = f"{BACKEND_URL}/payments-in"
    headers = {
        "Content-Type": "application/json",
        "X-Api-Key": BACKEND_API_KEY,
    }
    try:
        resp = requests.post(
            url,
            data=json.dumps({"payments": payments}, ensure_ascii=False),
            headers=headers,
            timeout=30,
        )
    except requests.RequestException as e:
        raise BackendApiError(f"Chyba sítě při volání backendu: {e}") from e

    if resp.status_code != 200:
        raise BackendApiError(f"Backend vrátil HTTP {resp.status_code}: {resp.text[:300]}")

    try:
        return resp.json()
    except ValueError as e:
        raise BackendApiError(f"Neplatný JSON z backendu: {e}") from e


def send_heartbeat() -> None:
    """POST /shop-api/heartbeat"""
    url = f"{BACKEND_URL}/heartbeat"
    headers = {"X-Api-Key": BACKEND_API_KEY}
    try:
        requests.post(url, headers=headers, timeout=10)
    except requests.RequestException as e:
        logger.warning("Heartbeat selhal: %s", e)


# ----------------------- Hlavní smyčka -----------------------
def run() -> int:
    logger.info("=" * 60)
    logger.info("Virtuální Azyl bank daemon spouští se.")
    logger.info("Poll interval: %d s", POLL_INTERVAL)
    logger.info("Backend: %s", BACKEND_URL)
    logger.info("=" * 60)

    if not FIO_API_TOKEN:
        logger.error("FIO_API_TOKEN není v .env nastaven!")
        return 1
    if not BACKEND_API_KEY:
        logger.error("BACKEND_API_KEY není v .env nastaven!")
        return 1

    poll_count = 0
    consecutive_errors = 0

    while _running:
        poll_count += 1
        start = time.time()
        try:
            transactions = fetch_new_transactions()
            if transactions:
                result = push_payments_to_backend(transactions)
                logger.info(
                    "Odesláno do backendu: %d nových, %d už existuje, %d spárováno, %d errors.",
                    result.get("received", 0),
                    result.get("already_exists", 0),
                    result.get("matched", 0),
                    len(result.get("errors", [])),
                )
                if result.get("errors"):
                    for err in result["errors"]:
                        logger.warning("Backend error: %s", err)

            # Heartbeat
            if poll_count % HEARTBEAT_EVERY_N_POLLS == 0:
                send_heartbeat()

            consecutive_errors = 0
        except (FioApiError, BackendApiError) as e:
            consecutive_errors += 1
            logger.error("Chyba (%d. za sebou): %s", consecutive_errors, e)
            # Exponential backoff při opakovaných chybách
            if consecutive_errors >= 3:
                backoff = min(300, POLL_INTERVAL * 2 ** (consecutive_errors - 2))
                logger.warning("Backoff %d s kvůli opakovaným chybám.", backoff)
                time.sleep(backoff)
                continue
        except Exception as e:  # noqa: BLE001
            consecutive_errors += 1
            logger.exception("Neočekávaná chyba: %s", e)

        # Čekání do dalšího polu (ale ať to reaguje na signál)
        elapsed = time.time() - start
        to_sleep = max(1, POLL_INTERVAL - int(elapsed))
        for _ in range(to_sleep):
            if not _running:
                break
            time.sleep(1)

    logger.info("Bank daemon ukončen.")
    return 0


if __name__ == "__main__":
    sys.exit(run())
