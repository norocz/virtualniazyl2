<?php
declare(strict_types=1);

namespace App\Model\Orm\Enums;

enum PayoutBatchStatusEnum: string
{
    case Draft = 'draft';
    case Exported = 'exported';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Rozpracováno',
            self::Exported  => 'Exportováno',
            self::Sent      => 'Odesláno',
            self::Cancelled => 'Zrušeno',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft     => 'bg-warning text-dark',
            self::Exported  => 'bg-info',
            self::Sent      => 'bg-success',
            self::Cancelled => 'bg-secondary',
        };
    }
}
