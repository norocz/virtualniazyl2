<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use App\Model\Orm\Enums\RefundStatusEnum;
use App\Model\Orm\Enums\RefundInitiatorEnum;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shop_refunds')]
class ShopRefund
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: ShopOrder::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false)]
    private ShopOrder $order;

    #[ORM\Column(type: 'integer', name: 'payments_in_id', nullable: true)]
    private ?int $paymentsInId = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(type: 'string', length: 64, name: 'refund_account')]
    private string $refundAccount;

    #[ORM\Column(type: 'string', length: 8, name: 'refund_bank_code', nullable: true)]
    private ?string $refundBankCode = null;

    #[ORM\Column(type: 'string', length: 255, name: 'refund_receiver_name', nullable: true)]
    private ?string $refundReceiverName = null;

    #[ORM\Column(type: 'string', length: 512)]
    private string $reason;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: RefundInitiatorEnum::class, name: 'initiated_by')]
    private RefundInitiatorEnum $initiatedBy;

    #[ORM\ManyToOne(targetEntity: Users::class)]
    #[ORM\JoinColumn(name: 'initiated_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Users $initiatedByUser = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: RefundStatusEnum::class, name: 'refund_status')]
    private RefundStatusEnum $refundStatus;

    #[ORM\ManyToOne(targetEntity: ShopPayoutBatch::class)]
    #[ORM\JoinColumn(name: 'batch_id', referencedColumnName: 'id', nullable: true)]
    private ?ShopPayoutBatch $batch = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'sent_at', nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'created_at')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->refundStatus = RefundStatusEnum::Pending;
    }

    public function getId(): int { return $this->id; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getPaymentsInId(): ?int { return $this->paymentsInId; }
    public function getAmount(): float { return (float)$this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getRefundAccount(): string { return $this->refundAccount; }
    public function getRefundBankCode(): ?string { return $this->refundBankCode; }
    public function getRefundReceiverName(): ?string { return $this->refundReceiverName; }
    public function getReason(): string { return $this->reason; }
    public function getInitiatedBy(): RefundInitiatorEnum { return $this->initiatedBy; }
    public function getInitiatedByUser(): ?Users { return $this->initiatedByUser; }
    public function getRefundStatus(): RefundStatusEnum { return $this->refundStatus; }
    public function getBatch(): ?ShopPayoutBatch { return $this->batch; }
    public function getSentAt(): ?DateTimeImmutable { return $this->sentAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    public function setOrder(ShopOrder $o): self { $this->order = $o; return $this; }
    public function setPaymentsInId(?int $id): self { $this->paymentsInId = $id; return $this; }
    public function setAmount(float $a): self { $this->amount = (string)$a; return $this; }
    public function setRefundAccount(string $a): self { $this->refundAccount = $a; return $this; }
    public function setRefundBankCode(?string $c): self { $this->refundBankCode = $c; return $this; }
    public function setRefundReceiverName(?string $n): self { $this->refundReceiverName = $n; return $this; }
    public function setReason(string $r): self { $this->reason = $r; return $this; }
    public function setInitiatedBy(RefundInitiatorEnum $i): self { $this->initiatedBy = $i; return $this; }
    public function setInitiatedByUser(?Users $u): self { $this->initiatedByUser = $u; return $this; }

    public function markSent(): self
    {
        $this->refundStatus = RefundStatusEnum::Sent;
        $this->sentAt = new DateTimeImmutable();
        return $this;
    }

    public function markQueued(ShopPayoutBatch $batch): self
    {
        $this->batch = $batch;
        $this->refundStatus = RefundStatusEnum::Queued;
        return $this;
    }
}
