<?php
declare(strict_types=1);

namespace App\Model\Orm\Enums;

enum RefundStatusEnum: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Čeká',
            self::Queued     => 'Zařazeno',
            self::Sent       => 'Odesláno',
            self::Cancelled  => 'Zrušeno',
        };
    }
}
