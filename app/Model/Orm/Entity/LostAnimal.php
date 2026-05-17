<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nette\Utils\Random;

#[ORM\Entity]
#[ORM\Table(name: 'lost_animals')]
class LostAnimal
{
    const STATUS_SEARCHING = 'searching';
    const STATUS_FOUND     = 'found';
    const STATUS_NOT_FOUND = 'not_found';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Users::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private Users $user;

    #[ORM\ManyToOne(targetEntity: Species::class)]
    #[ORM\JoinColumn(name: 'species_id', referencedColumnName: 'id', nullable: false)]
    private Species $species;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $sex = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $aliases = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $breed = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'text')]
    private string $eventDescription;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $hasChip = false;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $chipNumber = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $hasTattoo = false;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $tattooValue = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $specialMarks = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $location;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $lat = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $lon = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $lostAt;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'searching'])]
    private string $status = self::STATUS_SEARCHING;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $secretToken;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDeleted = false;

    #[ORM\OneToMany(targetEntity: Photo::class, mappedBy: 'lostAnimal', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $photos;

    #[ORM\OneToMany(targetEntity: AnimalSighting::class, mappedBy: 'lostAnimal', cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $sightings;

    public function __construct()
    {
        $this->photos    = new ArrayCollection();
        $this->sightings = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->secretToken = Random::generate(48);
    }

    public function getId(): int { return $this->id; }

    public function getUser(): Users { return $this->user; }
    public function setUser(Users $user): self { $this->user = $user; return $this; }

    public function getSpecies(): Species { return $this->species; }
    public function setSpecies(Species $species): self { $this->species = $species; return $this; }

    public function getSex(): ?string { return $this->sex; }
    public function setSex(?string $sex): self { $this->sex = $sex; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }

    public function getAliases(): ?string { return $this->aliases; }
    public function setAliases(?string $aliases): self { $this->aliases = $aliases; return $this; }

    public function getBreed(): ?string { return $this->breed; }
    public function setBreed(?string $breed): self { $this->breed = $breed; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = $description; return $this; }

    public function getEventDescription(): string { return $this->eventDescription; }
    public function setEventDescription(string $eventDescription): self { $this->eventDescription = $eventDescription; return $this; }

    public function isHasChip(): bool { return $this->hasChip; }
    public function setHasChip(bool $hasChip): self { $this->hasChip = $hasChip; return $this; }

    public function getChipNumber(): ?string { return $this->chipNumber; }
    public function setChipNumber(?string $chipNumber): self { $this->chipNumber = $chipNumber; return $this; }

    public function isHasTattoo(): bool { return $this->hasTattoo; }
    public function setHasTattoo(bool $hasTattoo): self { $this->hasTattoo = $hasTattoo; return $this; }

    public function getTattooValue(): ?string { return $this->tattooValue; }
    public function setTattooValue(?string $tattooValue): self { $this->tattooValue = $tattooValue; return $this; }

    public function getSpecialMarks(): ?string { return $this->specialMarks; }
    public function setSpecialMarks(?string $specialMarks): self { $this->specialMarks = $specialMarks; return $this; }

    public function getLocation(): string { return $this->location; }
    public function setLocation(string $location): self { $this->location = $location; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): self { $this->city = $city; return $this; }

    public function getLat(): ?float { return $this->lat !== null ? (float)$this->lat : null; }
    public function setLat(?float $lat): self { $this->lat = $lat !== null ? (string)$lat : null; return $this; }

    public function getLon(): ?float { return $this->lon !== null ? (float)$this->lon : null; }
    public function setLon(?float $lon): self { $this->lon = $lon !== null ? (string)$lon : null; return $this; }

    public function getLostAt(): DateTimeImmutable { return $this->lostAt; }
    public function setLostAt(DateTimeImmutable $lostAt): self { $this->lostAt = $lostAt; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function isSearching(): bool { return $this->status === self::STATUS_SEARCHING; }

    public function getSecretToken(): string { return $this->secretToken; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    public function isDeleted(): bool { return $this->isDeleted; }
    public function setIsDeleted(bool $isDeleted): self { $this->isDeleted = $isDeleted; return $this; }

    public function getPhotos(): Collection { return $this->photos; }
    public function getSightings(): Collection { return $this->sightings; }

    public function hasGps(): bool { return $this->lat !== null && $this->lon !== null; }

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

    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_FOUND     => 'Nalezeno',
            self::STATUS_NOT_FOUND => 'Nenalezeno',
            default                => 'Hledáme',
        };
    }
}
