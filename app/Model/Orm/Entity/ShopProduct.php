<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shop_products')]
class ShopProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Azyl::class)]
    #[ORM\JoinColumn(name: 'azyl_id', referencedColumnName: 'id', nullable: false)]
    private Azyl $azyl;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(type: 'integer')]
    private int $stock = 0;

    #[ORM\Column(type: 'boolean', name: 'unlimited_stock')]
    private bool $unlimitedStock = false;

    #[ORM\Column(type: 'integer', name: 'main_photo', nullable: true)]
    private ?int $mainPhoto = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: 'integer', name: 'weight_grams', nullable: true)]
    private ?int $weightGrams = null;

    #[ORM\Column(type: 'boolean', name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean', name: 'is_approved')]
    private bool $isApproved = false;

    #[ORM\Column(type: 'integer', name: 'approved_by', nullable: true)]
    private ?int $approvedBy = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'approved_at', nullable: true)]
    private ?DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', name: 'updated_at', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ShopProductPhoto::class, cascade: ['persist', 'remove'])]
    private Collection $photos;

    public function __construct()
    {
        $this->photos = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    // ---------- Gettery ----------
    public function getId(): int { return $this->id; }
    public function getAzyl(): Azyl { return $this->azyl; }
    public function getName(): string { return $this->name; }
    public function getShortDescription(): ?string { return $this->shortDescription; }
    public function getDescription(): ?string { return $this->description; }
    public function getPrice(): float { return (float)$this->price; }
    public function getCurrency(): string { return $this->currency; }
    public function getStock(): int { return $this->stock; }
    public function isUnlimitedStock(): bool { return $this->unlimitedStock; }
    public function getMainPhoto(): ?int { return $this->mainPhoto; }
    public function getCategory(): ?string { return $this->category; }
    public function getSku(): ?string { return $this->sku; }
    public function getWeightGrams(): ?int { return $this->weightGrams; }
    public function isActive(): bool { return $this->isActive; }
    public function isApproved(): bool { return $this->isApproved; }
    public function getApprovedBy(): ?int { return $this->approvedBy; }
    public function getApprovedAt(): ?DateTimeImmutable { return $this->approvedAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTimeImmutable { return $this->updatedAt; }
    public function getPhotos(): Collection { return $this->photos; }

    /**
     * Je produkt dostupný k objednání? (aktivní + schválený + skladem nebo neomezené)
     */
    public function isAvailable(): bool
    {
        if (!$this->isActive || !$this->isApproved) {
            return false;
        }
        return $this->unlimitedStock || $this->stock > 0;
    }

    // ---------- Settery ----------
    public function setAzyl(Azyl $azyl): self { $this->azyl = $azyl; return $this; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function setShortDescription(?string $s): self { $this->shortDescription = $s; return $this; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }
    public function setPrice(float $price): self { $this->price = (string)$price; return $this; }
    public function setCurrency(string $currency): self { $this->currency = $currency; return $this; }
    public function setStock(int $stock): self { $this->stock = $stock; return $this; }
    public function setUnlimitedStock(bool $v): self { $this->unlimitedStock = $v; return $this; }
    public function setMainPhoto(?int $id): self { $this->mainPhoto = $id; return $this; }
    public function setCategory(?string $c): self { $this->category = $c; return $this; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
    public function setWeightGrams(?int $g): self { $this->weightGrams = $g; return $this; }
    public function setIsActive(bool $v): self { $this->isActive = $v; return $this; }

    public function approve(int $adminUserId): self
    {
        $this->isApproved = true;
        $this->approvedBy = $adminUserId;
        $this->approvedAt = new DateTimeImmutable();
        return $this;
    }

    public function unapprove(): self
    {
        $this->isApproved = false;
        $this->approvedBy = null;
        $this->approvedAt = null;
        return $this;
    }

    public function touchUpdatedAt(): self
    {
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    /**
     * Sníží skladovou zásobu o daný počet. Používá se při vytváření objednávky.
     * @throws \RuntimeException Pokud nemáme dost skladem.
     */
    public function decreaseStock(int $qty): self
    {
        if ($this->unlimitedStock) {
            return $this;
        }
        if ($this->stock < $qty) {
            throw new \RuntimeException(sprintf(
                'Nedostatečná skladová zásoba produktu "%s". K dispozici: %d, požadováno: %d',
                $this->name, $this->stock, $qty
            ));
        }
        $this->stock -= $qty;
        return $this;
    }

    public function increaseStock(int $qty): self
    {
        if (!$this->unlimitedStock) {
            $this->stock += $qty;
        }
        return $this;
    }
}
