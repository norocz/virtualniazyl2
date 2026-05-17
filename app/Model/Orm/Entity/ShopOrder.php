<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use App\Model\Orm\Enums\ShopOrderStatusEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shop_orders')]
class ShopOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 10, unique: true, name: 'order_number')]
    private string $orderNumber;

    #[ORM\ManyToOne(targetEntity: Users::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Users $user = null;

    #[ORM\ManyToOne(targetEntity: Azyl::class)]
    #[ORM\JoinColumn(name: 'azyl_id', referencedColumnName: 'id', nullable: false)]
    private Azyl $azyl;

    // ---- kontakty ----
    #[ORM\Column(type: 'string', length: 255, name: 'buyer_name')]
    private string $buyerName;

    #[ORM\Column(type: 'string', length: 255, name: 'buyer_email')]
    private string $buyerEmail;

    #[ORM\Column(type: 'string', length: 32, name: 'buyer_phone', nullable: true)]
    private ?string $buyerPhone = null;

    // ---- adresa ----
    #[ORM\Column(type: 'string', length: 255, name: 'delivery_street', nullable: true)]
    private ?string $deliveryStreet = null;

    #[ORM\Column(type: 'string', length: 32, name: 'delivery_house_number', nullable: true)]
    private ?string $deliveryHouseNumber = null;

    #[ORM\Column(type: 'string', length: 255, name: 'delivery_city', nullable: true)]
    private ?string $deliveryCity = null;

    #[ORM\Column(type: 'string', length: 10, name: 'delivery_psc', nullable: true)]
    private ?string $deliveryPsc = null;

    #[ORM\Column(type: 'string', length: 64, name: 'delivery_country', nullable: true)]
    private ?string $deliveryCountry = 'Česká republika';

    #[ORM\Column(type: 'text', name: 'delivery_note', nullable: true)]
    private ?string $deliveryNote = null;

    // ---- částky ----
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, name: 'items_total')]
    private string $itemsTotal = '0';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, name: 'shipping_cost')]
    private string $shippingCost = '0';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, name: 'total_amount')]
    private string $totalAmount = '0';

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, name: 'fee_percent')]
    private string $feePercent = '5.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, name: 'fee_amount')]
    private string $feeAmount = '0';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, name: 'payout_amount')]
    private string $payoutAmount = '0';

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ShopOrderStatusEnum::class, name: 'order_status')]
    private ShopOrderStatusEnum $orderStatus;

    #[ORM\Column(type: 'datetime_immutable', name: 'payment_received_at', nullable: true)]
    private ?DateTimeImmutable $paymentReceivedAt = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'accepted_at', nullable: true)]
    private ?DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'shipped_at', nullable: true)]
    private ?DateTimeImmutable $shippedAt = null;

    #[ORM\Column(type: 'string', length: 100, name: 'shipping_tracking', nullable: true)]
    private ?string $shippingTracking = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'delivered_at', nullable: true)]
    private ?DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'expires_at')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'text', name: 'internal_note', nullable: true)]
    private ?string $internalNote = null;

    #[ORM\Column(type: 'string', length: 5, name: 'preferred_language', nullable: true)]
    private ?string $preferredLanguage = 'cs';

    #[ORM\Column(type: 'datetime_immutable', name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', name: 'updated_at', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: ShopOrderItem::class, cascade: ['persist', 'remove'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->orderStatus = ShopOrderStatusEnum::New;
    }

    // ---------- Gettery ----------
    public function getId(): int { return $this->id; }
    public function getOrderNumber(): string { return $this->orderNumber; }
    public function getUser(): ?Users { return $this->user; }
    public function getAzyl(): Azyl { return $this->azyl; }
    public function getBuyerName(): string { return $this->buyerName; }
    public function getBuyerEmail(): string { return $this->buyerEmail; }
    public function getBuyerPhone(): ?string { return $this->buyerPhone; }
    public function getDeliveryStreet(): ?string { return $this->deliveryStreet; }
    public function getDeliveryHouseNumber(): ?string { return $this->deliveryHouseNumber; }
    public function getDeliveryCity(): ?string { return $this->deliveryCity; }
    public function getDeliveryPsc(): ?string { return $this->deliveryPsc; }
    public function getDeliveryCountry(): ?string { return $this->deliveryCountry; }
    public function getDeliveryNote(): ?string { return $this->deliveryNote; }
    public function getItemsTotal(): float { return (float)$this->itemsTotal; }
    public function getShippingCost(): float { return (float)$this->shippingCost; }
    public function getTotalAmount(): float { return (float)$this->totalAmount; }
    public function getCurrency(): string { return $this->currency; }
    public function getFeePercent(): float { return (float)$this->feePercent; }
    public function getFeeAmount(): float { return (float)$this->feeAmount; }
    public function getPayoutAmount(): float { return (float)$this->payoutAmount; }
    public function getOrderStatus(): ShopOrderStatusEnum { return $this->orderStatus; }
    public function getPaymentReceivedAt(): ?DateTimeImmutable { return $this->paymentReceivedAt; }
    public function getAcceptedAt(): ?DateTimeImmutable { return $this->acceptedAt; }
    public function getShippedAt(): ?DateTimeImmutable { return $this->shippedAt; }
    public function getShippingTracking(): ?string { return $this->shippingTracking; }
    public function getDeliveredAt(): ?DateTimeImmutable { return $this->deliveredAt; }
    public function getExpiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function getInternalNote(): ?string { return $this->internalNote; }
    public function getPreferredLanguage(): ?string { return $this->preferredLanguage; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getItems(): Collection { return $this->items; }

    public function isPaid(): bool { return $this->paymentReceivedAt !== null; }
    public function isExpired(): bool { return new DateTimeImmutable() > $this->expiresAt; }
    public function isAnonymous(): bool { return $this->user === null; }

    // ---------- Settery ----------
    public function setOrderNumber(string $n): self { $this->orderNumber = $n; return $this; }
    public function setUser(?Users $user): self { $this->user = $user; return $this; }
    public function setAzyl(Azyl $azyl): self { $this->azyl = $azyl; return $this; }
    public function setBuyerName(string $n): self { $this->buyerName = $n; return $this; }
    public function setBuyerEmail(string $e): self { $this->buyerEmail = $e; return $this; }
    public function setBuyerPhone(?string $p): self { $this->buyerPhone = $p; return $this; }

    public function setDeliveryAddress(?string $street, ?string $houseNumber, ?string $city, ?string $psc, ?string $country = 'Česká republika'): self
    {
        $this->deliveryStreet = $street;
        $this->deliveryHouseNumber = $houseNumber;
        $this->deliveryCity = $city;
        $this->deliveryPsc = $psc;
        $this->deliveryCountry = $country;
        return $this;
    }

    public function setDeliveryNote(?string $n): self { $this->deliveryNote = $n; return $this; }

    public function setAmounts(float $itemsTotal, float $shippingCost, float $feePercent): self
    {
        $this->itemsTotal = (string)$itemsTotal;
        $this->shippingCost = (string)$shippingCost;
        $total = round($itemsTotal + $shippingCost, 2);
        $this->totalAmount = (string)$total;
        $this->feePercent = (string)$feePercent;
        // Poplatek se počítá z totalAmount (i z poštovného? business rozhodnutí - default ano)
        $fee = round($total * $feePercent / 100, 2);
        $this->feeAmount = (string)$fee;
        $this->payoutAmount = (string)round($total - $fee, 2);
        return $this;
    }

    public function setCurrency(string $c): self { $this->currency = $c; return $this; }
    public function setOrderStatus(ShopOrderStatusEnum $s): self
    {
        $this->orderStatus = $s;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function setExpiresAt(DateTimeImmutable $e): self { $this->expiresAt = $e; return $this; }
    public function setInternalNote(?string $n): self { $this->internalNote = $n; return $this; }
    public function setPreferredLanguage(?string $l): self { $this->preferredLanguage = $l; return $this; }

    public function markPaid(): self
    {
        $this->paymentReceivedAt = new DateTimeImmutable();
        $this->orderStatus = ShopOrderStatusEnum::Paid;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function markAccepted(): self
    {
        $this->acceptedAt = new DateTimeImmutable();
        $this->orderStatus = ShopOrderStatusEnum::Accepted;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function markShipped(?string $tracking = null): self
    {
        $this->shippedAt = new DateTimeImmutable();
        $this->shippingTracking = $tracking;
        $this->orderStatus = ShopOrderStatusEnum::Shipped;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function markDelivered(): self
    {
        $this->deliveredAt = new DateTimeImmutable();
        $this->orderStatus = ShopOrderStatusEnum::Delivered;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function addItem(ShopOrderItem $item): self
    {
        $item->setOrder($this);
        $this->items->add($item);
        return $this;
    }

    public function getFullDeliveryAddress(): string
    {
        $parts = array_filter([
            $this->deliveryStreet . ' ' . $this->deliveryHouseNumber,
            $this->deliveryPsc . ' ' . $this->deliveryCity,
            $this->deliveryCountry,
        ]);
        return trim(implode(', ', array_map('trim', $parts)), ', ');
    }
}
