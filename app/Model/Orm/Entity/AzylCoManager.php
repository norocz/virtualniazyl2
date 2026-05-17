<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'azyl_co_managers')]
class AzylCoManager
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Azyl::class)]
    #[ORM\JoinColumn(name: 'azyl_id', nullable: false)]
    private Azyl $azyl;

    #[ORM\ManyToOne(targetEntity: Users::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private Users $user;

    #[ORM\ManyToOne(targetEntity: Users::class)]
    #[ORM\JoinColumn(name: 'invited_by_id', nullable: false)]
    private Users $invitedBy;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $inviteToken;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $invitedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $acceptedAt = null;

    public function getAzyl(): Azyl { return $this->azyl; }
    public function setAzyl(Azyl $azyl): self { $this->azyl = $azyl; return $this; }

    public function getUser(): Users { return $this->user; }
    public function setUser(Users $user): self { $this->user = $user; return $this; }

    public function getInvitedBy(): Users { return $this->invitedBy; }
    public function setInvitedBy(Users $user): self { $this->invitedBy = $user; return $this; }

    public function getInviteToken(): string { return $this->inviteToken; }
    public function setInviteToken(string $token): self { $this->inviteToken = $token; return $this; }

    public function getInvitedAt(): DateTimeImmutable { return $this->invitedAt; }
    public function setInvitedAt(DateTimeImmutable $dt): self { $this->invitedAt = $dt; return $this; }

    public function getAcceptedAt(): ?DateTimeImmutable { return $this->acceptedAt; }
    public function setAcceptedAt(?DateTimeImmutable $dt): self { $this->acceptedAt = $dt; return $this; }

    public function isAccepted(): bool { return $this->acceptedAt !== null; }
}
