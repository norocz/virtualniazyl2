<?php
declare(strict_types=1);

namespace App\Model\Orm\Enums;

enum RefundInitiatorEnum: string
{
    case Buyer = 'buyer';
    case Azyl = 'azyl';
    case Admin = 'admin';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Buyer  => 'Kupující',
            self::Azyl   => 'Azyl',
            self::Admin  => 'Admin',
            self::System => 'Systém',
        };
    }
}
