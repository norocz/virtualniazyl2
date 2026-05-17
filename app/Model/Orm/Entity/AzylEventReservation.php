<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use App\Model\Orm\Repository\AzylEventReservationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AzylEventReservationRepository::class)]
#[ORM\Table(name: 'azyl_event_reservations')]
class AzylEventReservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: AzylEvent::class, inversedBy: 'reservations')]
    #[ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id', nullable: false)]
    private AzylEvent $event;

    #[ORM\ManyToOne(targetEntity: Users::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private ?Users $user = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $name = null;

    /** Token pro zrušení registrace (odesílá se e-mailem). */
    #[ORM\Column(type: 'string', length: 64, nullable: true, unique: true)]
    private ?string $token = null;

    /** Pro opakující se události: datum konkrétního termínu. Pro jednorázové null. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $occurrenceDate = null;

    #[ORM\Column(type: 'integer')]
    private int $participantsCount = 1;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** confirmed | waitlist | cancelled */
    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'confirmed';

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }

    public function getEvent(): AzylEvent { return $this->event; }
    public function setEvent(AzylEvent $event): self { $this->event = $event; return $this; }

    public function getUser(): ?Users { return $this->user; }
    public function setUser(?Users $user): self { $this->user = $user; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }

    public function getToken(): ?string { return $this->token; }
    public function setToken(?string $token): self { $this->token = $token; return $this; }

    public function getEffectiveEmail(): ?string
    {
        return $this->email ?? $this->user?->getEmail();
    }

    public function getEffectiveName(): string
    {
        return $this->name ?? $this->user?->getUserName() ?? $this->email ?? '—';
    }

    public function getOccurrenceDate(): ?DateTimeImmutable { return $this->occurrenceDate; }
    public function setOccurrenceDate(?DateTimeImmutable $v): self { $this->occurrenceDate = $v; return $this; }

    public function getParticipantsCount(): int { return $this->participantsCount; }
    public function setParticipantsCount(int $v): self { $this->participantsCount = max(1, $v); return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $v): self { $this->note = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): self { $this->status = $v; return $this; }

    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isWaitlist(): bool  { return $this->status === 'waitlist'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
