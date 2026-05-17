<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_azyl_follows')]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_AZYL', columns: ['user_id', 'azyl_id'])]
class UserAzylFollow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Users::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Users $user;

    #[ORM\ManyToOne(targetEntity: Azyl::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Azyl $azyl;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Users $user, Azyl $azyl)
    {
        $this->user = $user;
        $this->azyl = $azyl;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getUser(): Users { return $this->user; }
    public function getAzyl(): Azyl { return $this->azyl; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
