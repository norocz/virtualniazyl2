<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use App\Model\Orm\Enums\PayoutStatusEnum;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shop_payouts')]
class ShopPayout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\OneToOne(targetEntity: ShopOrder::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false)]
    private ShopOrder $order;

    #[ORM\ManyToOne(targetEntity: Azyl::class)]
    #[ORM\JoinColumn(name: 'azyl_id', referencedColumnName: 'id', nullable: false)]
    private Azyl $azyl;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, name: 'fee_amount')]
    private string $feeAmount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PayoutStatusEnum::class, name: 'payout_status')]
    private PayoutStatusEnum $payoutStatus;

    #[ORM\Column(type: 'string', length: 64, name: 'azyl_bank_account', nullable: true)]
    private ?string $azylBankAccount = null;

    #[ORM\Column(type: 'string', length: 8, name: 'azyl_bank_code', nullable: true)]
    private ?string $azylBankCode = null;

    #[ORM\ManyToOne(targetEntity: ShopPayoutBatch::class)]
    #[ORM\JoinColumn(name: 'batch_id', referencedColumnName: 'id', nullable: true)]
    private ?ShopPayoutBatch $batch = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', name: 'queued_at', nullable: true)]
    private ?DateTimeImmutable $queuedAt = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'sent_at', nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->payoutStatus = PayoutStatusEnum::Pending;
    }

    public function getId(): int { return $this->id; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getAzyl(): Azyl { return $this->azyl; }
    public function getAmount(): float { return (float)$this->amount; }
    public function getFeeAmount(): float { return (float)$this->feeAmount; }
    public function getCurrency(): string { return $this->currency; }
    public function getPayoutStatus(): PayoutStatusEnum { return $this->payoutStatus; }
    public function getAzylBankAccount(): ?string { return $this->azylBankAccount; }
    public function getAzylBankCode(): ?string { return $this->azylBankCode; }
    public function getBatch(): ?ShopPayoutBatch { return $this->batch; }
    public function getNotes(): ?string { return $this->notes; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getQueuedAt(): ?DateTimeImmutable { return $this->queuedAt; }
    public function getSentAt(): ?DateTimeImmutable { return $this->sentAt; }

    public function getFullAccount(): string
    {
        return $this->azylBankAccount . '/' . $this->azylBankCode;
    }

    public function setOrder(ShopOrder $o): self { $this->order = $o; return $this; }
    public function setAzyl(Azyl $a): self { $this->azyl = $a; return $this; }
    public function setAmount(float $a): self { $this->amount = (string)$a; return $this; }
    public function setFeeAmount(float $f): self { $this->feeAmount = (string)$f; return $this; }
    public function setCurrency(string $c): self { $this->currency = $c; return $this; }
    public function setAzylBankAccount(?string $a): self { $this->azylBankAccount = $a; return $this; }
    public function setAzylBankCode(?string $c): self { $this->azylBankCode = $c; return $this; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }

    public function markQueued(ShopPayoutBatch $batch): self
    {
        $this->batch = $batch;
        $this->payoutStatus = PayoutStatusEnum::Queued;
        $this->queuedAt = new DateTimeImmutable();
        return $this;
    }

    public function markSent(): self
    {
        $this->payoutStatus = PayoutStatusEnum::Sent;
        $this->sentAt = new DateTimeImmutable();
        return $this;
    }

    public function markCancelled(?string $reason = null): self
    {
        $this->payoutStatus = PayoutStatusEnum::Cancelled;
        if ($reason) {
            $this->notes = trim(($this->notes ?? '') . "\n[cancelled] " . $reason);
        }
        return $this;
    }

    public function markOnHold(?string $reason = null): self
    {
        $this->payoutStatus = PayoutStatusEnum::OnHold;
        if ($reason) {
            $this->notes = trim(($this->notes ?? '') . "\n[on_hold] " . $reason);
        }
        return $this;
    }
}
