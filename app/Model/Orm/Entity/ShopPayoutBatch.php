<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use App\Model\Orm\Enums\PayoutBatchStatusEnum;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shop_payout_batches')]
class ShopPayoutBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 20, unique: true, name: 'batch_number')]
    private string $batchNumber;

    #[ORM\ManyToOne(targetEntity: Users::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: false)]
    private Users $createdBy;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, name: 'total_amount')]
    private string $totalAmount = '0';

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(type: 'integer', name: 'item_count')]
    private int $itemCount = 0;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PayoutBatchStatusEnum::class, name: 'batch_status')]
    private PayoutBatchStatusEnum $batchStatus;

    #[ORM\Column(type: 'string', length: 20, name: 'export_format', nullable: true)]
    private ?string $exportFormat = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'exported_at', nullable: true)]
    private ?DateTimeImmutable $exportedAt = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'sent_at', nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'created_at')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->batchStatus = PayoutBatchStatusEnum::Draft;
        // Generujeme batch number jako BATCH-YYYYMMDD-XXXX
        $this->batchNumber = 'BATCH-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    }

    public function getId(): int { return $this->id; }
    public function getBatchNumber(): string { return $this->batchNumber; }
    public function getCreatedBy(): Users { return $this->createdBy; }
    public function getTotalAmount(): float { return (float)$this->totalAmount; }
    public function getCurrency(): string { return $this->currency; }
    public function getItemCount(): int { return $this->itemCount; }
    public function getBatchStatus(): PayoutBatchStatusEnum { return $this->batchStatus; }
    public function getExportFormat(): ?string { return $this->exportFormat; }
    public function getExportedAt(): ?DateTimeImmutable { return $this->exportedAt; }
    public function getSentAt(): ?DateTimeImmutable { return $this->sentAt; }
    public function getNotes(): ?string { return $this->notes; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    public function setCreatedBy(Users $u): self { $this->createdBy = $u; return $this; }
    public function setTotalAmount(float $a): self { $this->totalAmount = (string)$a; return $this; }
    public function setItemCount(int $c): self { $this->itemCount = $c; return $this; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }

    public function markExported(string $format): self
    {
        $this->batchStatus = PayoutBatchStatusEnum::Exported;
        $this->exportFormat = $format;
        $this->exportedAt = new DateTimeImmutable();
        return $this;
    }

    public function markSent(): self
    {
        $this->batchStatus = PayoutBatchStatusEnum::Sent;
        $this->sentAt = new DateTimeImmutable();
        return $this;
    }

    public function markCancelled(): self
    {
        $this->batchStatus = PayoutBatchStatusEnum::Cancelled;
        return $this;
    }
}
