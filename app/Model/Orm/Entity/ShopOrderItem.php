<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shop_order_items')]
class ShopOrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: ShopOrder::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ShopOrder $order;

    #[ORM\ManyToOne(targetEntity: ShopProduct::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ShopProduct $product = null;

    #[ORM\Column(type: 'string', length: 255, name: 'product_name')]
    private string $productName;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, name: 'unit_price')]
    private string $unitPrice;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $subtotal;

    #[ORM\Column(type: 'string', length: 512, name: 'product_photo_path', nullable: true)]
    private ?string $productPhotoPath = null;

    public function getId(): int { return $this->id; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getProduct(): ?ShopProduct { return $this->product; }
    public function getProductName(): string { return $this->productName; }
    public function getUnitPrice(): float { return (float)$this->unitPrice; }
    public function getQuantity(): int { return $this->quantity; }
    public function getSubtotal(): float { return (float)$this->subtotal; }
    public function getProductPhotoPath(): ?string { return $this->productPhotoPath; }

    public function setOrder(ShopOrder $order): self { $this->order = $order; return $this; }
    public function setProduct(?ShopProduct $product): self { $this->product = $product; return $this; }
    public function setProductName(string $n): self { $this->productName = $n; return $this; }
    public function setProductPhotoPath(?string $p): self { $this->productPhotoPath = $p; return $this; }

    public function setPricing(float $unitPrice, int $quantity): self
    {
        $this->unitPrice = (string)$unitPrice;
        $this->quantity = $quantity;
        $this->subtotal = (string)round($unitPrice * $quantity, 2);
        return $this;
    }
}
