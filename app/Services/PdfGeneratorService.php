<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\ShopDocument;
use Latte\Engine;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Generuje PDF dokladů z Latte šablon.
 *
 * Používá mPDF - musí být nainstalován přes composer:
 *   composer require mpdf/mpdf
 *
 * mPDF má výbornou podporu češtiny, UTF-8 a CSS. Alternativy (Dompdf,
 * wkhtmltopdf) mají různé problémy - mPDF je pro české prostředí nejlepší.
 */
class PdfGeneratorService
{
    private Engine $latte;
    private string $templateDir;
    private string $storageDir;
    private SystemSettingsReader $settings;

    public function __construct(
        string $templateDir,
        string $storageDir,
        SystemSettingsReader $settings
    )
    {
        $this->latte = new Engine();
        $this->latte->setTempDirectory(dirname(__DIR__, 2) . '/temp/latte-pdf');
        $this->templateDir = rtrim($templateDir, '/');
        $this->storageDir = rtrim($storageDir, '/');
        $this->settings = $settings;

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Vygeneruje PDF pro doklad.
     *
     * @return string Relativní cesta k uloženému PDF (od $storageDir)
     */
    public function generate(ShopDocument $doc): string
    {
        $templateFile = match ($doc->getDocumentType()->value) {
            'customer_receipt'    => 'customer-receipt.latte',
            'commission_invoice'  => 'commission-invoice.latte',
            'payout_statement'    => 'payout-statement.latte',
            default => throw new \InvalidArgumentException('Neznámý typ dokladu'),
        };

        $html = $this->latte->renderToString(
            $this->templateDir . '/' . $templateFile,
            [
                'doc'   => $doc,
                'items' => $doc->getItemsJson(),
                'meta'  => $doc->getMetadataJson() ?? [],
                'logo'  => $this->getLogoAsBase64(),
            ]
        );

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_left'   => 15,
            'margin_right'  => 15,
            'margin_top'    => 15,
            'margin_bottom' => 18,
            'margin_header' => 6,
            'margin_footer' => 6,
            'default_font'  => 'dejavusans',
            'tempDir'       => dirname(__DIR__, 2) . '/temp/mpdf',
        ]);

        $mpdf->SetTitle($doc->getDocumentType()->label() . ' ' . $doc->getDocumentNumber());
        $mpdf->SetAuthor($doc->getIssuerName());
        $mpdf->SetCreator('Virtuální azyl - eshop');

        $mpdf->WriteHTML($html);

        // Struktura: /2026/04/PP2026000123.pdf
        $year = $doc->getIssuedAt()->format('Y');
        $month = $doc->getIssuedAt()->format('m');
        $relativeDir = $year . '/' . $month;
        $relativePath = $relativeDir . '/' . $doc->getDocumentNumber() . '.pdf';

        $absoluteDir = $this->storageDir . '/' . $relativeDir;
        if (!is_dir($absoluteDir)) {
            @mkdir($absoluteDir, 0755, true);
        }

        $absolutePath = $this->storageDir . '/' . $relativePath;
        $mpdf->Output($absolutePath, Destination::FILE);

        return $relativePath;
    }

    /**
     * Logo vloženo do PDF jako base64 data URI.
     * Pokud se nepodaří načíst, vrátí null.
     */
    private function getLogoAsBase64(): ?string
    {
        $logoPath = (string)$this->settings->get('invoice.logo_path', '');
        if ($logoPath === '') {
            return null;
        }

        // Může být relativní k www/ nebo absolutní
        $absolute = $logoPath;
        if (!file_exists($absolute)) {
            $absolute = dirname(__DIR__, 2) . '/www/' . ltrim($logoPath, '/');
        }
        if (!file_exists($absolute)) {
            return null;
        }

        $data = @file_get_contents($absolute);
        if ($data === false) {
            return null;
        }

        $mime = mime_content_type($absolute) ?: 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}
