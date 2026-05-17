<?php
declare(strict_types=1);

namespace App\Model\Orm\Enums;

enum PayoutStatusEnum: string
{
    case Pending = 'pending';       // čeká na zařazení
    case Queued = 'queued';         // v batchi
    case Sent = 'sent';             // odesláno
    case Cancelled = 'cancelled';
    case OnHold = 'on_hold';        // superadmin pozastavil

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Čeká',
            self::Queued     => 'Zařazeno',
            self::Sent       => 'Odesláno',
            self::Cancelled  => 'Zrušeno',
            self::OnHold     => 'Pozastaveno',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending    => 'bg-warning text-dark',
            self::Queued     => 'bg-info',
            self::Sent       => 'bg-success',
            self::Cancelled  => 'bg-secondary',
            self::OnHold     => 'bg-danger',
        };
    }
}
