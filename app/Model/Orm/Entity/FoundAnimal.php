<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nette\Utils\Random;

#[ORM\Entity]
#[ORM\Table(name: 'found_animals')]
class FoundAnimal
{
    const STATUS_OPEN     = 'open';
    const STATUS_MATCHED  = 'matched';
    const STATUS_RESOLVED = 'resolved';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Users::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private ?Users $user = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $reporterName = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $reporterEmail = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $reporterPhone = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isEmailConfirmed = false;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $confirmToken = null;

    #[ORM\ManyToOne(targetEntity: Species::class)]
    #[ORM\JoinColumn(name: 'species_id', referencedColumnName: 'id', nullable: false)]
    private Species $species;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $sex = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $breed = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'string', length: 255)]
    private string $location;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $lat = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $lon = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $foundAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'open'])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $secretToken;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDeleted = false;

    #[ORM\OneToMany(targetEntity: Photo::class, mappedBy: 'foundAnimal', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $photos;

    public function __construct()
    {
        $this->photos      = new ArrayCollection();
        $this->createdAt   = new DateTimeImmutable();
        $this->secretToken = Random::generate(48);
        $this->confirmToken = Random::generate(48);
    }

    public function getId(): int { return $this->id; }

    public function getUser(): ?Users { return $this->user; }
    public function setUser(?Users $user): self { $this->user = $user; return $this; }

    public function getReporterName(): ?string { return $this->reporterName; }
    public function setReporterName(?string $reporterName): self { $this->reporterName = $reporterName; return $this; }

    public function getReporterEmail(): ?string { return $this->reporterEmail; }
    public function setReporterEmail(?string $reporterEmail): self { $this->reporterEmail = $reporterEmail; return $this; }

    public function getReporterPhone(): ?string { return $this->reporterPhone; }
    public function setReporterPhone(?string $reporterPhone): self { $this->reporterPhone = $reporterPhone; return $this; }

    public function isEmailConfirmed(): bool { return $this->isEmailConfirmed; }
    public function setIsEmailConfirmed(bool $v): self { $this->isEmailConfirmed = $v; return $this; }

    public function getConfirmToken(): ?string { return $this->confirmToken; }
    public function clearConfirmToken(): self { $this->confirmToken = null; return $this; }

    public function getSpecies(): Species { return $this->species; }
    public function setSpecies(Species $species): self { $this->species = $species; return $this; }

    public function getSex(): ?string { return $this->sex; }
    public function setSex(?string $sex): self { $this->sex = $sex; return $this; }

    public function getBreed(): ?string { return $this->breed; }
    public function setBreed(?string $breed): self { $this->breed = $breed; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = $description; return $this; }

    public function getLocation(): string { return $this->location; }
    public function setLocation(string $location): self { $this->location = $location; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): self { $this->city = $city; return $this; }

    public function getLat(): ?float { return $this->lat !== null ? (float)$this->lat : null; }
    public function setLat(?float $lat): self { $this->lat = $lat !== null ? (string)$lat : null; return $this; }

    public function getLon(): ?float { return $this->lon !== null ? (float)$this->lon : null; }
    public function setLon(?float $lon): self { $this->lon = $lon !== null ? (string)$lon : null; return $this; }

    public function getFoundAt(): DateTimeImmutable { return $this->foundAt; }
    public function setFoundAt(DateTimeImmutable $foundAt): self { $this->foundAt = $foundAt; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = $note; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getSecretToken(): string { return $this->secretToken; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    public function isDeleted(): bool { return $this->isDeleted; }
    public function setIsDeleted(bool $v): self { $this->isDeleted = $v; return $this; }

    public function getPhotos(): Collection { return $this->photos; }

    public function hasGps(): bool { return $this->lat !== null && $this->lon !== null; }

    public function getEffectiveEmail(): ?string
    {
        return $this->user?->getEmail() ?? $this->reporterEmail;
    }

    public function getEffectiveName(): ?string
    {
        return $this->user?->getUserName() ?? $this->reporterName;
    }

    public function getEffectivePhone(): ?string
    {
        return $this->reporterPhone;
    }

    public function isContactVisible(): bool
    {
        return $this->user !== null || $this->isEmailConfirmed;
    }

    public function distanceTo(float $lat, float $lon): ?float
    {
        if (!$this->hasGps()) {
            return null;
        }
        $earthRadius = 6371;
        $dLat = deg2rad($lat - $this->getLat());
        $dLon = deg2rad($lon - $this->getLon());
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($this->getLat())) * cos(deg2rad($lat)) * sin($dLon / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
