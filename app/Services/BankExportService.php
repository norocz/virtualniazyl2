<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\ShopPayout;
use App\Model\Orm\Entity\ShopPayoutBatch;
use App\Model\Orm\Entity\ShopRefund;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exporty platebních dávek do formátů podporovaných českými bankami.
 *
 * Podporované formáty:
 *  - ABO standard (univerzální pro ČS, KB, ČSOB, Moneta, Raiffeisen)
 *  - CSV Fio
 *  - SEPA XML (pain.001.001.03) - pro SEPA převody (EUR)
 *
 * ABO je nejuniverzálnější - každá banka umí ABO import.
 * Doporučuju ho používat jako výchozí.
 *
 * Formát ABO (zjednodušeně):
 *   Header: 1501 | název souboru | číslo souboru | datum splatnosti | sender account | ...
 *   Body:   1 | účet cíl | kód banky | částka v haléřích | VS | KS | SS | text | ...
 *   Footer: 5 | počet položek | součet částek | ...
 */
class BankExportService
{
    public const FORMAT_ABO = 'abo';
    public const FORMAT_CSV_FIO = 'csv_fio';
    public const FORMAT_SEPA_XML = 'sepa_xml';

    private EntityManagerInterface $em;
    private SystemSettingsReader $settings;

    public function __construct(EntityManagerInterface $em, SystemSettingsReader $settings)
    {
        $this->em = $em;
        $this->settings = $settings;
    }

    /**
     * Exportuje batch do specifikovaného formátu.
     *
     * @return array{filename: string, content: string, mime: string}
     */
    public function exportBatch(ShopPayoutBatch $batch, string $format): array
    {
        $payouts = $this->em->getRepository(ShopPayout::class)->findBy(['batch' => $batch]);
        $refunds = $this->em->getRepository(ShopRefund::class)->findBy(['batch' => $batch]);

        return match ($format) {
            self::FORMAT_ABO      => $this->exportAbo($batch, $payouts, $refunds),
            self::FORMAT_CSV_FIO  => $this->exportCsvFio($batch, $payouts, $refunds),
            self::FORMAT_SEPA_XML => $this->exportSepaXml($batch, $payouts, $refunds),
            default => throw new \InvalidArgumentException('Neznámý formát: ' . $format),
        };
    }

    // =================================================================
    // ABO standard - text, univerzální
    // =================================================================
    //
    // Formát ABO podporuje většina českých bank (ČS, KB, ČSOB, Moneta,
    // Raiffeisen, UniCredit, Air Bank).
    //
    // Struktura souboru:
    //   UHL1501 <název><datum><sender_account>...\r\n
    //   UHL1 <cíl_účet>/<banka> <částka> <VS> <KS> <SS> <text>\r\n
    //   UHL5 <počet> <součet>\r\n
    //
    /**
     * @param ShopPayout[] $payouts
     * @param ShopRefund[] $refunds
     */
    private function exportAbo(ShopPayoutBatch $batch, array $payouts, array $refunds): array
    {
        $senderAccount = (string)$this->settings->get('shop.spolek_account', '');
        $senderBank = (string)$this->settings->get('shop.spolek_bank_code', '');

        if (empty($senderAccount) || empty($senderBank)) {
            throw new \RuntimeException('V system_settings chybí shop.spolek_account nebo shop.spolek_bank_code.');
        }

        $today = date('dmy');
        $batchId = substr($batch->getBatchNumber(), -5); // posledních 5 znaků jako ID
        $totalHalers = 0;
        $count = 0;
        $lines = [];

        // --- HEADER ---
        // Formát: UHL1501<SEND_DATE 6><SEND_ACCOUNT 16><PAYMENT_COUNT 6>
        // Ale různé banky mají mírně jiné varianty. Následuje typicky univerzální:
        $lines[] = sprintf(
            'UHL1%s%s%s %s',
            '1501',
            str_pad($senderAccount, 16, '0', STR_PAD_LEFT),
            $today,
            str_pad($batchId, 6, '0', STR_PAD_LEFT)
        );

        // --- BODY: Výplaty azylům ---
        foreach ($payouts as $p) {
            $amountHalers = (int)round($p->getAmount() * 100);
            $totalHalers += $amountHalers;
            $count++;

            // UHL1 <target_account>/<bank> <amount> <VS> <KS> <SS> <text>
            $lines[] = sprintf(
                '1 %s/%s %d %s 0558 0 %s',
                str_pad($p->getAzylBankAccount(), 16, '0', STR_PAD_LEFT),
                str_pad($p->getAzylBankCode(), 4, '0', STR_PAD_LEFT),
                $amountHalers,
                str_pad($p->getOrder()->getOrderNumber(), 10, '0', STR_PAD_LEFT),
                $this->sanitizeAbo('Eshop obj ' . $p->getOrder()->getOrderNumber())
            );
        }

        // --- BODY: Vratky zákazníkům ---
        foreach ($refunds as $r) {
            $amountHalers = (int)round($r->getAmount() * 100);
            $totalHalers += $amountHalers;
            $count++;

            $lines[] = sprintf(
                '1 %s/%s %d %s 0558 0 %s',
                str_pad($r->getRefundAccount(), 16, '0', STR_PAD_LEFT),
                str_pad($r->getRefundBankCode() ?? '0000', 4, '0', STR_PAD_LEFT),
                $amountHalers,
                str_pad($r->getOrder()->getOrderNumber(), 10, '0', STR_PAD_LEFT),
                $this->sanitizeAbo('Vratka obj ' . $r->getOrder()->getOrderNumber())
            );
        }

        // --- FOOTER ---
        $lines[] = sprintf('5 %d %d', $count, $totalHalers);

        return [
            'filename' => sprintf('batch_%s.kpc', $batch->getBatchNumber()),
            'content'  => implode("\r\n", $lines) . "\r\n",
            'mime'     => 'text/plain; charset=windows-1250',
        ];
    }

    /**
     * Sanitizace pro ABO - bez diakritiky, max 140 znaků, ASCII only.
     */
    private function sanitizeAbo(string $s): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^a-zA-Z0-9 .\-_\/]/', '', $s);
        return substr($s, 0, 140);
    }

    // =================================================================
    // Fio CSV - jednoduchý tabulkový formát pro Fio banku
    // =================================================================
    private function exportCsvFio(ShopPayoutBatch $batch, array $payouts, array $refunds): array
    {
        $lines = [];
        $lines[] = '"datum";"castka";"mena";"ucet_prijemce";"kod_banky";"vs";"ks";"ss";"zprava_prijemci"';
        $today = date('d.m.Y');

        foreach ($payouts as $p) {
            $lines[] = implode(';', [
                '"' . $today . '"',
                '"' . number_format($p->getAmount(), 2, '.', '') . '"',
                '"' . $p->getCurrency() . '"',
                '"' . $p->getAzylBankAccount() . '"',
                '"' . $p->getAzylBankCode() . '"',
                '"' . $p->getOrder()->getOrderNumber() . '"',
                '"0558"',
                '""',
                '"Eshop ' . $p->getOrder()->getOrderNumber() . '"',
            ]);
        }

        foreach ($refunds as $r) {
            $lines[] = implode(';', [
                '"' . $today . '"',
                '"' . number_format($r->getAmount(), 2, '.', '') . '"',
                '"' . $r->getCurrency() . '"',
                '"' . $r->getRefundAccount() . '"',
                '"' . ($r->getRefundBankCode() ?? '') . '"',
                '"' . $r->getOrder()->getOrderNumber() . '"',
                '"0558"',
                '""',
                '"Vratka ' . $r->getOrder()->getOrderNumber() . '"',
            ]);
        }

        return [
            'filename' => sprintf('batch_%s.csv', $batch->getBatchNumber()),
            'content'  => implode("\n", $lines),
            'mime'     => 'text/csv; charset=utf-8',
        ];
    }

    // =================================================================
    // SEPA XML (pain.001.001.03) - mezinárodní standard
    // =================================================================
    //
    // Podporován většinou evropských bank, dobrá volba pro budoucnost.
    // Nevýhoda: potřebuje IBAN (ne jen CZ account + bank code).

    private function exportSepaXml(ShopPayoutBatch $batch, array $payouts, array $refunds): array
    {
        $senderIban = (string)$this->settings->get('shop.spolek_iban', '');
        $senderBic = (string)$this->settings->get('shop.spolek_bic', '');
        $senderName = (string)$this->settings->get('shop.spolek_name', 'Virtualni azyl z.s.');

        if (empty($senderIban)) {
            throw new \RuntimeException('Pro SEPA export musí být v system_settings shop.spolek_iban.');
        }

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $xml->createElement('Document');
        $root->setAttribute('xmlns', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03');
        $xml->appendChild($root);

        $cstmrCdtTrfInitn = $xml->createElement('CstmrCdtTrfInitn');
        $root->appendChild($cstmrCdtTrfInitn);

        // --- Group Header ---
        $grpHdr = $xml->createElement('GrpHdr');
        $cstmrCdtTrfInitn->appendChild($grpHdr);
        $grpHdr->appendChild($xml->createElement('MsgId', $batch->getBatchNumber()));
        $grpHdr->appendChild($xml->createElement('CreDtTm', (new \DateTimeImmutable())->format('c')));

        $totalAmount = 0.0;
        $count = 0;
        foreach ($payouts as $p) { $totalAmount += $p->getAmount(); $count++; }
        foreach ($refunds as $r) { $totalAmount += $r->getAmount(); $count++; }

        $grpHdr->appendChild($xml->createElement('NbOfTxs', (string)$count));
        $grpHdr->appendChild($xml->createElement('CtrlSum', number_format($totalAmount, 2, '.', '')));

        $initgPty = $xml->createElement('InitgPty');
        $grpHdr->appendChild($initgPty);
        $initgPty->appendChild($xml->createElement('Nm', $senderName));

        // --- Payment Information ---
        $pmtInf = $xml->createElement('PmtInf');
        $cstmrCdtTrfInitn->appendChild($pmtInf);
        $pmtInf->appendChild($xml->createElement('PmtInfId', $batch->getBatchNumber()));
        $pmtInf->appendChild($xml->createElement('PmtMtd', 'TRF'));
        $pmtInf->appendChild($xml->createElement('NbOfTxs', (string)$count));
        $pmtInf->appendChild($xml->createElement('CtrlSum', number_format($totalAmount, 2, '.', '')));
        $pmtInf->appendChild($xml->createElement('ReqdExctnDt', date('Y-m-d')));

        // Sender
        $dbtr = $xml->createElement('Dbtr');
        $pmtInf->appendChild($dbtr);
        $dbtr->appendChild($xml->createElement('Nm', $senderName));

        $dbtrAcct = $xml->createElement('DbtrAcct');
        $pmtInf->appendChild($dbtrAcct);
        $dbtrAcctId = $xml->createElement('Id');
        $dbtrAcct->appendChild($dbtrAcctId);
        $dbtrAcctId->appendChild($xml->createElement('IBAN', $senderIban));

        if (!empty($senderBic)) {
            $dbtrAgt = $xml->createElement('DbtrAgt');
            $pmtInf->appendChild($dbtrAgt);
            $finInstnId = $xml->createElement('FinInstnId');
            $dbtrAgt->appendChild($finInstnId);
            $finInstnId->appendChild($xml->createElement('BIC', $senderBic));
        }

        // --- Jednotlivé platby ---
        foreach ($payouts as $p) {
            $this->appendSepaCreditTransfer(
                $xml,
                $pmtInf,
                $p->getOrder()->getOrderNumber(),
                $p->getAmount(),
                $p->getCurrency(),
                $this->accountToIban($p->getAzylBankAccount(), $p->getAzylBankCode()),
                $p->getAzyl()->getAzylName(),
                'Eshop ' . $p->getOrder()->getOrderNumber()
            );
        }

        foreach ($refunds as $r) {
            $this->appendSepaCreditTransfer(
                $xml,
                $pmtInf,
                'REF-' . $r->getOrder()->getOrderNumber(),
                $r->getAmount(),
                $r->getCurrency(),
                $this->accountToIban($r->getRefundAccount(), $r->getRefundBankCode() ?? '0000'),
                $r->getRefundReceiverName() ?? 'Zakaznik',
                'Vratka ' . $r->getOrder()->getOrderNumber()
            );
        }

        return [
            'filename' => sprintf('batch_%s.xml', $batch->getBatchNumber()),
            'content'  => $xml->saveXML(),
            'mime'     => 'application/xml; charset=utf-8',
        ];
    }

    private function appendSepaCreditTransfer(
        \DOMDocument $xml,
        \DOMElement $pmtInf,
        string $endToEndId,
        float $amount,
        string $currency,
        string $receiverIban,
        string $receiverName,
        string $remittance
    ): void
    {
        $cdtTrfTxInf = $xml->createElement('CdtTrfTxInf');
        $pmtInf->appendChild($cdtTrfTxInf);

        $pmtId = $xml->createElement('PmtId');
        $cdtTrfTxInf->appendChild($pmtId);
        $pmtId->appendChild($xml->createElement('EndToEndId', $endToEndId));

        $amt = $xml->createElement('Amt');
        $cdtTrfTxInf->appendChild($amt);
        $instdAmt = $xml->createElement('InstdAmt', number_format($amount, 2, '.', ''));
        $instdAmt->setAttribute('Ccy', $currency);
        $amt->appendChild($instdAmt);

        $cdtr = $xml->createElement('Cdtr');
        $cdtTrfTxInf->appendChild($cdtr);
        $cdtr->appendChild($xml->createElement('Nm', $this->sanitizeXmlText($receiverName)));

        $cdtrAcct = $xml->createElement('CdtrAcct');
        $cdtTrfTxInf->appendChild($cdtrAcct);
        $cdtrAcctId = $xml->createElement('Id');
        $cdtrAcct->appendChild($cdtrAcctId);
        $cdtrAcctId->appendChild($xml->createElement('IBAN', $receiverIban));

        $rmtInf = $xml->createElement('RmtInf');
        $cdtTrfTxInf->appendChild($rmtInf);
        $rmtInf->appendChild($xml->createElement('Ustrd', $this->sanitizeXmlText($remittance)));
    }

    /**
     * Převede české číslo účtu (prefix-number/bank) na IBAN.
     * Algoritmus: CZkk + bankcode + prefix(6) + accountNumber(10)
     */
    private function accountToIban(string $account, string $bankCode): string
    {
        // Rozdělíme prefix-number
        $prefix = '';
        $accNum = $account;
        if (str_contains($account, '-')) {
            [$prefix, $accNum] = explode('-', $account, 2);
        }
        $prefix = str_pad($prefix, 6, '0', STR_PAD_LEFT);
        $accNum = str_pad($accNum, 10, '0', STR_PAD_LEFT);
        $bankCode = str_pad($bankCode, 4, '0', STR_PAD_LEFT);

        // BBAN = bankCode + prefix + accountNumber
        $bban = $bankCode . $prefix . $accNum;

        // Vypočítat kontrolní číslice podle ISO 13616
        // CZ = 12 35, kontrolu počítáme na (BBAN + "CZ00") % 97
        $checkString = $bban . '123500';
        // Mod 97 na dlouhém čísle - po kouskách
        $remainder = '';
        foreach (str_split($checkString) as $digit) {
            $remainder = (int)(($remainder . $digit) % 97);
        }
        $check = str_pad((string)(98 - (int)$remainder), 2, '0', STR_PAD_LEFT);

        return 'CZ' . $check . $bban;
    }

    private function sanitizeXmlText(string $s): string
    {
        // SEPA standard omezuje znakovou sadu
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^a-zA-Z0-9 .,\-\/()]/', '', $s);
        return substr($s, 0, 70); // SEPA limit
    }
}
