<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\ShopOrder;
use Defr\QRPlatba\QRPlatba;
use Tracy\Debugger;

/**
 * Generuje QR platbu (SPD formát) pro objednávku eshopu.
 *
 * Platba jde vždy na účet spolku (z system_settings).
 * Azylu se pak přepošle přes payout batch.
 */
class ShopQrService
{
    private SystemSettingsReader $settings;

    public function __construct(SystemSettingsReader $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Vygeneruje QR kód pro objednávku.
     *
     * @return string base64-encoded PNG obrázek (pripraveny pro <img src="data:image/png;base64,...">)
     *                nebo error placeholder text
     */
    public function generateQrForOrder(ShopOrder $order): string
    {
        $spolekAccount = (string)$this->settings->get('shop.spolek_account', '');
        $spolekBank = (string)$this->settings->get('shop.spolek_bank_code', '');

        if (empty($spolekAccount) || empty($spolekBank)) {
            return '';
        }

        try {
            $qr = new QRPlatba();
            $qr->setAccount($spolekAccount . '/' . $spolekBank)
                ->setVariableSymbol((int)$order->getOrderNumber())
                ->setMessage('Eshop objednavka ' . $order->getOrderNumber())
                ->setAmount($order->getTotalAmount())
                ->setCurrency($order->getCurrency())
                ->setDueDate($order->getExpiresAt());

            // QRPlatba::getQRCodeImage() vrací data URI nebo přímo base64 podle verze knihovny
            return $qr->getQRCodeImage();
        } catch (\Throwable $e) {
            Debugger::log('QR generation failed for order ' . $order->getOrderNumber() . ': ' . $e->getMessage(), 'shop');
            return '';
        }
    }

    /**
     * Textová verze platebních údajů pro případ, že QR není dostupný
     * (třeba když není Freetype).
     */
    public function getPaymentDetails(ShopOrder $order): array
    {
        return [
            'account'      => (string)$this->settings->get('shop.spolek_account', ''),
            'bankCode'     => (string)$this->settings->get('shop.spolek_bank_code', ''),
            'amount'       => $order->getTotalAmount(),
            'currency'     => $order->getCurrency(),
            'vs'           => $order->getOrderNumber(),
            'message'      => 'Eshop objednavka ' . $order->getOrderNumber(),
            'dueDate'      => $order->getExpiresAt(),
        ];
    }
}
