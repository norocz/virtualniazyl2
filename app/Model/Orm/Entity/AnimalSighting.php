<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'animal_sightings')]
class AnimalSighting
{
    const TYPE_SIGHTING  = 'sighting';
    const TYPE_HAS_ANIMAL = 'has_animal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: LostAnimal::class, inversedBy: 'sightings')]
    #[ORM\JoinColumn(name: 'lost_animal_id', referencedColumnName: 'id', nullable: false)]
    private LostAnimal $lostAnimal;

    #[ORM\Column(type: 'string', length: 20)]
    private string $type;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $lat = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $lon = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $contactName = null;

    #[ORM\Column(type: 'string', length: 150)]
    private string $contactEmail;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isNotified = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }

    public function getLostAnimal(): LostAnimal { return $this->lostAnimal; }
    public function setLostAnimal(LostAnimal $lostAnimal): self { $this->lostAnimal = $lostAnimal; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getTypeLabel(): string
    {
        return $this->type === self::TYPE_HAS_ANIMAL ? 'Mám zvíře u sebe' : 'Viděl/a jsem zvíře';
    }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): self { $this->message = $message; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }

    public function getLat(): ?float { return $this->lat !== null ? (float)$this->lat : null; }
    public function setLat(?float $lat): self { $this->lat = $lat !== null ? (string)$lat : null; return $this; }

    public function getLon(): ?float { return $this->lon !== null ? (float)$this->lon : null; }
    public function setLon(?float $lon): self { $this->lon = $lon !== null ? (string)$lon : null; return $this; }

    public function getContactName(): ?string { return $this->contactName; }
    public function setContactName(?string $contactName): self { $this->contactName = $contactName; return $this; }

    public function getContactEmail(): string { return $this->contactEmail; }
    public function setContactEmail(string $contactEmail): self { $this->contactEmail = $contactEmail; return $this; }

    public function getContactPhone(): ?string { return $this->contactPhone; }
    public function setContactPhone(?string $contactPhone): self { $this->contactPhone = $contactPhone; return $this; }

    public function isNotified(): bool { return $this->isNotified; }
    public function setIsNotified(bool $v): self { $this->isNotified = $v; return $this; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
