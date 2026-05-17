<?php
declare(strict_types=1);

namespace App\Model\Orm\Enums;

enum ShopDocumentTypeEnum: string
{
    /** Potvrzení o zaplacení pro zákazníka (komisionářský model) */
    case CustomerReceipt = 'customer_receipt';

    /** Faktura spolku azylu za provozní poplatek (jen z provize) */
    case CommissionInvoice = 'commission_invoice';

    /** Informativní výpis o výplatě pro azyl */
    case PayoutStatement = 'payout_statement';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReceipt    => 'Potvrzení o platbě',
            self::CommissionInvoice  => 'Faktura - provozní poplatek',
            self::PayoutStatement    => 'Výpis o výplatě',
        };
    }

    /**
     * Prefix pro číslování dokladů v rámci roku.
     */
    public function numberPrefix(): string
    {
        return match ($this) {
            self::CustomerReceipt    => 'PP',  // Potvrzení Platby
            self::CommissionInvoice  => 'F',   // Faktura
            self::PayoutStatement    => 'VV',  // Výpis Výplaty
        };
    }
}
