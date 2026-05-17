<?php
declare(strict_types=1);

namespace App\Model\Orm\Enums;

enum RecurrenceTypeEnum: string
{
    case None      = 'none';
    case Weekly    = 'weekly';
    case Biweekly  = 'biweekly';
    case Monthly   = 'monthly';

    public function label(): string
    {
        return match($this) {
            self::None     => 'Jednorázová',
            self::Weekly   => 'Každý týden',
            self::Biweekly => 'Každé dva týdny',
            self::Monthly  => 'Každý měsíc',
        };
    }

    public function intervalDays(): ?int
    {
        return match($this) {
            self::None     => null,
            self::Weekly   => 7,
            self::Biweekly => 14,
            self::Monthly  => null, // handled specially (+1 month)
        };
    }
}
