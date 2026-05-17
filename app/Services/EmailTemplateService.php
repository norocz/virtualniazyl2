<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\ShopOrder;
use App\Model\Orm\Entity\ShopPayout;
use App\Model\Orm\Entity\ShopRefund;
use Latte\Engine;
use Tracy\Debugger;

/**
 * Renderování Latte e-mailových šablon eshopu.
 *
 * Plně aplikovaná verze - obsahuje:
 *  - Základní e-maily (order-created, order-paid, ...)
 *  - E-maily s PDF přílohami (po platbě, po výplatě)
 *  - E-maily azylu během flow (čeká na platbu, zaplaceno, výplata)
 *
 * Závisí na DocumentService pro generování PDF (vyžaduje balíček #4).
 * Pokud DocumentService není dostupný (jen balíček #3 nasazen), metody
 * sendOrderPaidWithReceipt() a sendAzylPayoutSent() se chovají jako
 * jejich verze bez příloh.
 */
class EmailTemplateService
{
    private Engine $latte;
    private string $templateDir;
    private EmailService $emailService;
    private TranslationService $translationService;
    private SystemSettingsReader $settings;
    private ?DocumentService $documentService;

    public function __construct(
        string $templateDir,
        EmailService $emailService,
        TranslationService $translationService,
        SystemSettingsReader $settings,
        ?DocumentService $documentService = null
    )
    {
        $this->latte = new Engine();
        $this->latte->setTempDirectory(dirname(__DIR__, 2) . '/temp/latte');
        $this->templateDir = rtrim($templateDir, '/');
        $this->emailService = $emailService;
        $this->translationService = $translationService;
        $this->settings = $settings;
        $this->documentService = $documentService;
    }

    // =========================================================
    // Objednávka přijata (zákazník) - bez přílohy, ještě není zaplaceno
    // =========================================================

    public function sendOrderCreated(ShopOrder $order, string $qrImage, array $paymentDetails): void
    {
        $this->render('order-created.latte', [
            'order' => $order,
            'qrImage' => $qrImage,
            'paymentDetails' => $paymentDetails,
            'baseUrl' => $this->getBaseUrl(),
            'lang' => $order->getPreferredLanguage() ?? 'cs',
        ], $order->getBuyerEmail(),
            $this->t('Objednávka {n} byla přijata', ['n' => $order->getOrderNumber()],
                $order->getPreferredLanguage()));
    }

    // =========================================================
    // Nová objednávka azylu (PŘED platbou - "neodesílejte!")
    // =========================================================

    public function sendAzylNewOrderPending(ShopOrder $order): void
    {
        $azylEmail = $order->getAzyl()->getEmail();
        if (empty($azylEmail)) return;

        $this->render('azyl-new-order-pending.latte', [
            'order' => $order,
            'baseUrl' => $this->getBaseUrl(),
            'lang' => 'cs',
        ], $azylEmail,
            sprintf('📋 Nová objednávka %s - čeká na platbu', $order->getOrderNumber()));
    }

    // =========================================================
    // Platba přijata (zákazník) - S přílohou Customer Receipt PDF
    // =========================================================

    public function sendOrderPaidWithReceipt(ShopOrder $order): void
    {
        if ($this->documentService === null) {
            // Fallback - bez PDF přílohy
            $this->render('order-paid.latte', [
                'order' => $order,
                'baseUrl' => $this->getBaseUrl(),
                'lang' => $order->getPreferredLanguage() ?? 'cs',
            ], $order->getBuyerEmail(),
                $this->t('Platba {n} přijata', ['n' => $order->getOrderNumber()],
                    $order->getPreferredLanguage()));
            return;
        }

        $document = $this->documentService->issueCustomerReceipt($order);
        $pdfPath = $this->documentService->ensurePdf($document);

        $this->renderWithAttachments('order-paid-with-receipt.latte', [
            'order' => $order,
            'document' => $document,
            'baseUrl' => $this->getBaseUrl(),
            'lang' => $order->getPreferredLanguage() ?? 'cs',
        ], $order->getBuyerEmail(),
            sprintf('Platba %s přijata - potvrzení v příloze', $order->getOrderNumber()), [
            ['path' => $pdfPath, 'filename' => $document->getDocumentNumber() . '.pdf', 'mime' => 'application/pdf'],
        ]);
    }

    // =========================================================
    // Platba přijata (azyl) - bez přílohy, faktury budou až po výplatě
    // =========================================================

    public function sendAzylOrderPaid(ShopOrder $order): void
    {
        $azylEmail = $order->getAzyl()->getEmail();
        if (empty($azylEmail)) return;

        $this->render('azyl-order-paid.latte', [
            'order' => $order,
            'baseUrl' => $this->getBaseUrl(),
            'lang' => 'cs',
        ], $azylEmail,
            sprintf('✅ Objednávka %s zaplacena - připravte k odeslání', $order->getOrderNumber()));
    }

    // =========================================================
    // Odesláno (zákazník)
    // =========================================================

    public function sendOrderShipped(ShopOrder $order): void
    {
        $this->render('order-shipped.latte', [
            'order' => $order,
            'baseUrl' => $this->getBaseUrl(),
            'lang' => $order->getPreferredLanguage() ?? 'cs',
        ], $order->getBuyerEmail(),
            $this->t('Vaše objednávka {n} byla odeslána',
                ['n' => $order->getOrderNumber()],
                $order->getPreferredLanguage()));
    }

    // =========================================================
    // Storno (zákazník)
    // =========================================================

    public function sendOrderCancelled(ShopOrder $order, string $reason): void
    {
        $this->render('order-cancelled.latte', [
            'order' => $order,
            'reason' => $reason,
            'baseUrl' => $this->getBaseUrl(),
            'lang' => $order->getPreferredLanguage() ?? 'cs',
        ], $order->getBuyerEmail(),
            $this->t('Objednávka {n} stornována',
                ['n' => $order->getOrderNumber()],
                $order->getPreferredLanguage()));
    }

    // =========================================================
    // Výplata azylu - S přílohami (faktura + výpis)
    // =========================================================

    public function sendAzylPayoutSent(ShopOrder $order, ShopPayout $payout): void
    {
        $azylEmail = $order->getAzyl()->getEmail();
        if (empty($azylEmail)) return;

        $attachments = [];

        if ($this->documentService !== null) {
            $commissionInvoice = $this->documentService->issueCommissionInvoice($order, $payout);
            $payoutStatement = $this->documentService->issuePayoutStatement($order, $payout);

            $invoicePdf = $this->documentService->ensurePdf($commissionInvoice);
            $statementPdf = $this->documentService->ensurePdf($payoutStatement);

            $attachments = [
                ['path' => $invoicePdf, 'filename' => $commissionInvoice->getDocumentNumber() . '.pdf', 'mime' => 'application/pdf'],
                ['path' => $statementPdf, 'filename' => $payoutStatement->getDocumentNumber() . '.pdf', 'mime' => 'application/pdf'],
            ];

            $params = [
                'order' => $order,
                'payout' => $payout,
                'commissionInvoice' => $commissionInvoice,
                'payoutStatement' => $payoutStatement,
                'baseUrl' => $this->getBaseUrl(),
                'lang' => 'cs',
            ];
        } else {
            $params = [
                'order' => $order,
                'payout' => $payout,
                'commissionInvoice' => null,
                'payoutStatement' => null,
                'baseUrl' => $this->getBaseUrl(),
                'lang' => 'cs',
            ];
        }

        $this->renderWithAttachments('azyl-payout-sent.latte', $params, $azylEmail,
            sprintf('💰 Výplata %s Kč z objednávky %s - doklady v příloze',
                number_format($payout->getAmount(), 0, ',', ' '),
                $order->getOrderNumber()),
            $attachments);
    }

    // =========================================================
    // Refund odeslán (zákazník)
    // =========================================================

    public function sendRefundSent(ShopRefund $refund): void
    {
        $this->render('refund-sent.latte', [
            'refund' => $refund,
            'order' => $refund->getOrder(),
            'baseUrl' => $this->getBaseUrl(),
            'lang' => $refund->getOrder()->getPreferredLanguage() ?? 'cs',
        ], $refund->getOrder()->getBuyerEmail(),
            sprintf('Vratka pro objednávku %s odeslána',
                $refund->getOrder()->getOrderNumber()));
    }

    // =========================================================
    // Měsíční potvrzení výplat (azyl)
    // =========================================================

    public function sendAzylPayoutConfirmation(
        string $azylEmail,
        string $azylName,
        array $payouts,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo
    ): void
    {
        $totalAmount = 0.0;
        foreach ($payouts as $p) $totalAmount += $p->getAmount();

        $this->render('azyl-payout-confirmation.latte', [
            'azylName' => $azylName,
            'payouts' => $payouts,
            'totalAmount' => $totalAmount,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'baseUrl' => $this->getBaseUrl(),
            'lang' => 'cs',
        ], $azylEmail,
            sprintf('Potvrzení o výplatě %s - %s',
                $periodFrom->format('d.m.Y'), $periodTo->format('d.m.Y')));
    }

    // =========================================================
    // INTERNÍ
    // =========================================================

    private function render(string $template, array $params, string $to, string $subject): void
    {
        try {
            $templatePath = $this->templateDir . '/' . $template;
            if (!is_file($templatePath)) {
                throw new \RuntimeException('Šablona neexistuje: ' . $templatePath);
            }

            $lang = $params['lang'] ?? 'cs';
            if ($lang !== 'cs' && $this->translationService->isEnabled()) {
                $subject = $this->translationService->translate($subject, $lang);
            }

            $body = $this->latte->renderToString($templatePath, $params);

            $fromEmail = (string)$this->settings->get('shop.from_email',
                'Eshop Virtuální Azyl <shop@virtualniazyl.cz>');

            $this->emailService->sendEmail($fromEmail, $to, $subject, $body);
        } catch (\Throwable $e) {
            Debugger::log(
                'EmailTemplateService error (' . $template . '): ' . $e->getMessage(),
                'shop-email'
            );
        }
    }

    private function renderWithAttachments(
        string $template, array $params, string $to,
        string $subject, array $attachments
    ): void
    {
        try {
            $templatePath = $this->templateDir . '/' . $template;
            $body = $this->latte->renderToString($templatePath, $params);

            $lang = $params['lang'] ?? 'cs';
            if ($lang !== 'cs' && $this->translationService->isEnabled()) {
                $subject = $this->translationService->translate($subject, $lang);
            }

            $fromEmail = (string)$this->settings->get('shop.from_email',
                'Eshop Virtuální Azyl <shop@virtualniazyl.cz>');

            // Pokusíme se použít sendEmailWithAttachments, pokud existuje;
            // jinak fallback na obyčejný sendEmail bez přílohy
            if (method_exists($this->emailService, 'sendEmailWithAttachments')) {
                $this->emailService->sendEmailWithAttachments(
                    $fromEmail, $to, $subject, $body, $attachments
                );
            } else {
                Debugger::log(
                    'EmailService nemá sendEmailWithAttachments - posílám bez přílohy',
                    'shop-email'
                );
                $this->emailService->sendEmail($fromEmail, $to, $subject, $body);
            }
        } catch (\Throwable $e) {
            Debugger::log(
                'EmailTemplateService error (' . $template . '): ' . $e->getMessage(),
                'shop-email'
            );
        }
    }

    private function t(string $text, array $params = [], ?string $lang = 'cs'): string
    {
        $translated = $text;
        if ($lang !== null && $lang !== 'cs' && $this->translationService->isEnabled()) {
            $translated = $this->translationService->translate($text, $lang);
        }
        foreach ($params as $key => $value) {
            $translated = str_replace('{' . $key . '}', (string)$value, $translated);
        }
        return $translated;
    }

    private function getBaseUrl(): string
    {
        return rtrim((string)$this->settings->get('shop.base_url',
            'https://virtualniazyl.cz'), '/');
    }
}
