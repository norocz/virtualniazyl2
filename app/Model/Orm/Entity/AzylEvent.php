<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use App\Model\Orm\Enums\RecurrenceTypeEnum;
use App\Model\Orm\Repository\AzylEventRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AzylEventRepository::class)]
#[ORM\Table(name: 'azyl_events')]
class AzylEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Azyl::class)]
    #[ORM\JoinColumn(name: 'azyl_id', referencedColumnName: 'id', nullable: false)]
    private Azyl $azyl;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $dateFrom;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $dateTo;

    #[ORM\Column(type: 'string', length: 20, enumType: RecurrenceTypeEnum::class)]
    private RecurrenceTypeEnum $recurrenceType = RecurrenceTypeEnum::None;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $recurrenceEndDate = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxParticipants = null;

    #[ORM\Column(type: 'boolean')]
    private bool $registrationEnabled = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isPublished = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isDeleted = false;

    #[ORM\Column(type: 'integer', nullable: true, name: 'header_photo_id')]
    private ?int $headerPhotoId = null;

    #[ORM\OneToMany(targetEntity: AzylEventReservation::class, mappedBy: 'event', fetch: 'LAZY')]
    private Collection $reservations;

    #[ORM\OneToMany(targetEntity: Photo::class, mappedBy: 'azylEvent', fetch: 'LAZY')]
    private Collection $photos;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->reservations = new ArrayCollection();
        $this->photos       = new ArrayCollection();
        $this->createdAt    = new DateTimeImmutable();
    }

    // ── Getters / setters ────────────────────────────────────────────────

    public function getId(): int { return $this->id; }

    public function getAzyl(): Azyl { return $this->azyl; }
    public function setAzyl(Azyl $azyl): self { $this->azyl = $azyl; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getShortDescription(): ?string { return $this->shortDescription; }
    public function setShortDescription(?string $v): self { $this->shortDescription = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): self { $this->description = $v; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $v): self { $this->location = $v; return $this; }

    public function getDateFrom(): DateTimeImmutable { return $this->dateFrom; }
    public function setDateFrom(DateTimeImmutable $v): self { $this->dateFrom = $v; return $this; }

    public function getDateTo(): DateTimeImmutable { return $this->dateTo; }
    public function setDateTo(DateTimeImmutable $v): self { $this->dateTo = $v; return $this; }

    public function getRecurrenceType(): RecurrenceTypeEnum { return $this->recurrenceType; }
    public function setRecurrenceType(RecurrenceTypeEnum $v): self { $this->recurrenceType = $v; return $this; }

    public function getRecurrenceEndDate(): ?DateTimeImmutable { return $this->recurrenceEndDate; }
    public function setRecurrenceEndDate(?DateTimeImmutable $v): self { $this->recurrenceEndDate = $v; return $this; }

    public function getMaxParticipants(): ?int { return $this->maxParticipants; }
    public function setMaxParticipants(?int $v): self { $this->maxParticipants = $v; return $this; }

    public function isRegistrationEnabled(): bool { return $this->registrationEnabled; }
    public function setRegistrationEnabled(bool $v): self { $this->registrationEnabled = $v; return $this; }

    public function isPublished(): bool { return $this->isPublished; }
    public function setIsPublished(bool $v): self { $this->isPublished = $v; return $this; }

    public function isDeleted(): bool { return $this->isDeleted; }
    public function setIsDeleted(bool $v): self { $this->isDeleted = $v; return $this; }

    public function getHeaderPhotoId(): ?int { return $this->headerPhotoId; }
    public function setHeaderPhotoId(?int $v): self { $this->headerPhotoId = $v; return $this; }

    public function getReservations(): Collection { return $this->reservations; }
    public function getPhotos(): Collection { return $this->photos; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?DateTimeImmutable $v): self { $this->updatedAt = $v; return $this; }

    // ── Business logic ────────────────────────────────────────────────────

    public function isRecurring(): bool
    {
        return $this->recurrenceType !== RecurrenceTypeEnum::None;
    }

    public function isMultiDay(): bool
    {
        return $this->dateFrom->format('Y-m-d') !== $this->dateTo->format('Y-m-d');
    }

    public function isUpcoming(): bool
    {
        $now = new DateTimeImmutable();
        if ($this->isRecurring()) {
            $next = $this->getNextOccurrences(1);
            return !empty($next);
        }
        return $this->dateFrom > $now;
    }

    public function isPast(): bool
    {
        $now = new DateTimeImmutable();
        if ($this->isRecurring()) {
            return $this->recurrenceEndDate !== null && $this->recurrenceEndDate < $now;
        }
        return $this->dateTo < $now;
    }

    public function isOngoing(): bool
    {
        $now = new DateTimeImmutable();
        return $this->dateFrom <= $now && $this->dateTo >= $now;
    }

    /** Returns active (confirmed) reservation count, optionally for a specific recurring occurrence date. */
    public function getReservationCount(?DateTimeImmutable $occurrenceDate = null): int
    {
        $count = 0;
        foreach ($this->reservations as $r) {
            if ($r->getStatus() !== 'confirmed') {
                continue;
            }
            if ($occurrenceDate !== null) {
                if ($r->getOccurrenceDate()?->format('Y-m-d') !== $occurrenceDate->format('Y-m-d')) {
                    continue;
                }
            }
            $count += $r->getParticipantsCount();
        }
        return $count;
    }

    public function hasCapacity(?DateTimeImmutable $occurrenceDate = null): bool
    {
        if ($this->maxParticipants === null) {
            return true;
        }
        return $this->getReservationCount($occurrenceDate) < $this->maxParticipants;
    }

    public function getRemainingCapacity(?DateTimeImmutable $occurrenceDate = null): ?int
    {
        if ($this->maxParticipants === null) {
            return null;
        }
        return max(0, $this->maxParticipants - $this->getReservationCount($occurrenceDate));
    }

    /**
     * Returns up to $count upcoming occurrence start datetimes.
     * For non-recurring, returns the event start if it's still in the future.
     */
    public function getNextOccurrences(int $count = 5): array
    {
        $now = new DateTimeImmutable();

        if (!$this->isRecurring()) {
            return $this->dateFrom > $now ? [$this->dateFrom] : [];
        }

        $occurrences = [];
        $current = $this->dateFrom;

        while (count($occurrences) < $count) {
            if ($this->recurrenceEndDate !== null && $current > $this->recurrenceEndDate) {
                break;
            }
            if ($current > $now) {
                $occurrences[] = $current;
            }
            $current = $this->nextDate($current);
            // Safety: stop if somehow stuck
            if ($current > new DateTimeImmutable('+5 years')) {
                break;
            }
        }

        return $occurrences;
    }

    private function nextDate(DateTimeImmutable $from): DateTimeImmutable
    {
        return match($this->recurrenceType) {
            RecurrenceTypeEnum::Weekly   => $from->modify('+7 days'),
            RecurrenceTypeEnum::Biweekly => $from->modify('+14 days'),
            RecurrenceTypeEnum::Monthly  => $from->modify('+1 month'),
            default                      => $from->modify('+999 years'), // stop loop
        };
    }
}
