# Virtuální Azyl — bankovní démon

Python skript co běží na pozadí na serveru a každých 30 vteřin stahuje nové transakce z Fio banky a posílá je do PHP backendu.

## Instalace

```bash
# 1. Překopíruj soubory na server
scp -r python/ user@server:/var/www/virtualniazyl/

# 2. Na serveru - nainstaluj requirements
cd /var/www/virtualniazyl/python
pip3 install -r requirements.txt

# 3. Zkopíruj a vyplň .env
cp .env.example .env
nano .env   # doplň FIO_API_TOKEN, BACKEND_API_KEY

# 4. Otestuj ručně (měl by se přihlásit a poslat heartbeat)
python3 bank_daemon.py
# (ukonči Ctrl+C)

# 5. Nasad jako systemd službu
sudo cp vaz-bankdaemon.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable vaz-bankdaemon
sudo systemctl start vaz-bankdaemon

# 6. Zkontroluj
sudo systemctl status vaz-bankdaemon
sudo journalctl -u vaz-bankdaemon -f
```

## Fio API token

1. Přihlas se do [ib.fio.cz](https://ib.fio.cz)
2. Nastavení → API
3. Vytvoř nový token **jen pro čtení** (Get a view permission)
4. Zkopíruj do `.env` jako `FIO_API_TOKEN`

## Jak to funguje

1. Každých 30 s se zavolá `GET /v1/rest/last/{token}/transactions.json`
2. Fio si interně pamatuje "kurzor" - vrací jen nové transakce od posledního volání
3. Transakce se normalizují (Fio má divný column_X formát) a POST se na `/shop-api/payments-in`
4. PHP backend je uloží do `paymentsIn` tabulky a spáruje podle VS s objednávkami
5. Spárované objednávky přejdou do stavu `paid` → vytvoří se payout ve frontě
6. Při neočekávané platbě (storno, cizí VS) se vytvoří refund

## Monitoring

- `POST /shop-api/heartbeat` se posílá každých 5 minut → do `system_settings['shop.python_last_heartbeat']`
- V SuperAdmin šabloně se zobrazuje *"Python démon: před X sekundami"*
- Pokud je heartbeat starší než 5 min, zobrazí se červená varovná hláška

## Troubleshooting

**HTTP 409 z Fio API** — rate limit, Fio má ochranu proti častým voláním. Skript to loguje a čeká.

**HTTP 401 z backendu** — zkontroluj že `BACKEND_API_KEY` v `.env` se shoduje s `parameters.shopApi.key` v `secrets.neon`.

**Transakce se nepárují s objednávkami** — zkontroluj že VS (variabilní symbol) na bankovním výpisu je skutečně `order_number` objednávky (10místné). Problém bývá když zákazník nevloží správný VS.

## Kam dál

Aktuálně podporuje jen Fio banku. Pro ČS/KB/Raiffeisen/ČSOB je potřeba jiný adapter — ty banky mají jiné API (většinou XML místo JSON).

Struktura je ale připravená — stačí napsat `fetch_new_transactions_csob()` a podle banky v .env přepínat.
