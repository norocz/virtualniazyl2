<?php
declare(strict_types=1);

namespace App\Model\Orm\Enums;

/**
 * Stav objednávky eshopu.
 */
enum ShopOrderStatusEnum: string
{
    case New = 'new';             // čeká na zaplacení
    case Paid = 'paid';           // zaplaceno - spárováno s PaymentsIn
    case Accepted = 'accepted';   // azyl přijal
    case Shipped = 'shipped';     // azyl odeslal
    case Delivered = 'delivered'; // doručeno
    case Cancelled = 'cancelled'; // storno před platbou
    case Refunded = 'refunded';   // peníze vráceny
    case Problem = 'problem';     // k řešení superadminem

    public function label(): string
    {
        return match ($this) {
            self::New       => 'Čeká na zaplacení',
            self::Paid      => 'Zaplaceno',
            self::Accepted  => 'Přijato azylem',
            self::Shipped   => 'Odesláno',
            self::Delivered => 'Doručeno',
            self::Cancelled => 'Stornováno',
            self::Refunded  => 'Vráceno',
            self::Problem   => 'Problém',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::New       => 'bg-warning text-dark',
            self::Paid      => 'bg-info',
            self::Accepted  => 'bg-primary',
            self::Shipped   => 'bg-primary',
            self::Delivered => 'bg-success',
            self::Cancelled => 'bg-secondary',
            self::Refunded  => 'bg-secondary',
            self::Problem   => 'bg-danger',
        };
    }
}
