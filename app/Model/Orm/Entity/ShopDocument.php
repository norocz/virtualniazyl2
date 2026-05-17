<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use App\Model\Orm\Enums\ShopDocumentTypeEnum;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Účetní doklad (faktura, potvrzení, výpis).
 *
 * Entita je immutabilní po vystavení - jakmile je vytvořená, měla by se
 * upravovat jen PDF cache. Všechny ostatní údaje jsou snapshoty.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_documents')]
class ShopDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 20, unique: true, name: 'document_number')]
    private string $documentNumber;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: ShopDocumentTypeEnum::class, name: 'document_type')]
    private ShopDocumentTypeEnum $documentType;

    #[ORM\ManyToOne(targetEntity: ShopOrder::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ShopOrder $order;

    #[ORM\Column(type: 'integer', name: 'payout_id', nullable: true)]
    private ?int $payoutId = null;

    // ---- Issuer (vydavatel) ----
    #[ORM\Column(type: 'string', length: 255, name: 'issuer_name')]
    private string $issuerName;

    #[ORM\Column(type: 'string', length: 20, name: 'issuer_ico', nullable: true)]
    private ?string $issuerIco = null;

    #[ORM\Column(type: 'string', length: 20, name: 'issuer_dic', nullable: true)]
    private ?string $issuerDic = null;

    #[ORM\Column(type: 'text', name: 'issuer_address', nullable: true)]
    private ?string $issuerAddress = null;

    #[ORM\Column(type: 'string', length: 64, name: 'issuer_account', nullable: true)]
    private ?string $issuerAccount = null;

    #[ORM\Column(type: 'string', length: 8, name: 'issuer_bank_code', nullable: true)]
    private ?string $issuerBankCode = null;

    #[ORM\Column(type: 'text', name: 'issuer_registration', nullable: true)]
    private ?string $issuerRegistration = null;

    #[ORM\Column(type: 'boolean', name: 'issuer_vat_payer')]
    private bool $issuerVatPayer = false;

    // ---- Buyer (příjemce) ----
    #[ORM\Column(type: 'string', length: 255, name: 'buyer_name')]
    private string $buyerName;

    #[ORM\Column(type: 'string', length: 20, name: 'buyer_ico', nullable: true)]
    private ?string $buyerIco = null;

    #[ORM\Column(type: 'string', length: 20, name: 'buyer_dic', nullable: true)]
    private ?string $buyerDic = null;

    #[ORM\Column(type: 'text', name: 'buyer_address', nullable: true)]
    private ?string $buyerAddress = null;

    #[ORM\Column(type: 'string', length: 255, name: 'buyer_email', nullable: true)]
    private ?string $buyerEmail = null;

    // ---- Dates ----
    #[ORM\Column(type: 'datetime_immutable', name: 'issued_at')]
    private DateTimeImmutable $issuedAt;

    #[ORM\Column(type: 'date_immutable', name: 'taxable_supply_date', nullable: true)]
    private ?DateTimeImmutable $taxableSupplyDate = null;

    #[ORM\Column(type: 'date_immutable', name: 'due_date', nullable: true)]
    private ?DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'paid_at', nullable: true)]
    private ?DateTimeImmutable $paidAt = null;

    // ---- Amounts ----
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $subtotal = '0';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, name: 'vat_rate')]
    private string $vatRate = '0';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, name: 'vat_amount')]
    private string $vatAmount = '0';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $total = '0';

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'CZK';

    // ---- Payment ----
    #[ORM\Column(type: 'string', length: 20, name: 'variable_symbol', nullable: true)]
    private ?string $variableSymbol = null;

    #[ORM\Column(type: 'string', length: 32, name: 'payment_method', nullable: true)]
    private ?string $paymentMethod = null;

    // ---- Snapshot data (JSON) ----
    /** @var array<int, array<string, mixed>> */
    #[ORM\Column(type: 'json', name: 'items_json')]
    private array $itemsJson = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', name: 'metadata_json', nullable: true)]
    private ?array $metadataJson = null;

    // ---- PDF cache ----
    #[ORM\Column(type: 'string', length: 512, name: 'pdf_path', nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'pdf_generated_at', nullable: true)]
    private ?DateTimeImmutable $pdfGeneratedAt = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'created_at')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->issuedAt = new DateTimeImmutable();
    }

    // Gettery
    public function getId(): int { return $this->id; }
    public function getDocumentNumber(): string { return $this->documentNumber; }
    public function getDocumentType(): ShopDocumentTypeEnum { return $this->documentType; }
    public function getOrder(): ShopOrder { return $this->order; }
    public function getPayoutId(): ?int { return $this->payoutId; }
    public function getIssuerName(): string { return $this->issuerName; }
    public function getIssuerIco(): ?string { return $this->issuerIco; }
    public function getIssuerDic(): ?string { return $this->issuerDic; }
    public function getIssuerAddress(): ?string { return $this->issuerAddress; }
    public function getIssuerAccount(): ?string { return $this->issuerAccount; }
    public function getIssuerBankCode(): ?string { return $this->issuerBankCode; }
    public function getIssuerRegistration(): ?string { return $this->issuerRegistration; }
    public function isIssuerVatPayer(): bool { return $this->issuerVatPayer; }
    public function getBuyerName(): string { return $this->buyerName; }
    public function getBuyerIco(): ?string { return $this->buyerIco; }
    public function getBuyerDic(): ?string { return $this->buyerDic; }
    public function getBuyerAddress(): ?string { return $this->buyerAddress; }
    public function getBuyerEmail(): ?string { return $this->buyerEmail; }
    public function getIssuedAt(): DateTimeImmutable { return $this->issuedAt; }
    public function getTaxableSupplyDate(): ?DateTimeImmutable { return $this->taxableSupplyDate; }
    public function getDueDate(): ?DateTimeImmutable { return $this->dueDate; }
    public function getPaidAt(): ?DateTimeImmutable { return $this->paidAt; }
    public function getSubtotal(): float { return (float)$this->subtotal; }
    public function getVatRate(): float { return (float)$this->vatRate; }
    public function getVatAmount(): float { return (float)$this->vatAmount; }
    public function getTotal(): float { return (float)$this->total; }
    public function getCurrency(): string { return $this->currency; }
    public function getVariableSymbol(): ?string { return $this->variableSymbol; }
    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function getItemsJson(): array { return $this->itemsJson; }
    public function getMetadataJson(): ?array { return $this->metadataJson; }
    public function getPdfPath(): ?string { return $this->pdfPath; }
    public function getPdfGeneratedAt(): ?DateTimeImmutable { return $this->pdfGeneratedAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    // Settery (jen pro builder pattern při vytváření)
    public function setDocumentNumber(string $n): self { $this->documentNumber = $n; return $this; }
    public function setDocumentType(ShopDocumentTypeEnum $t): self { $this->documentType = $t; return $this; }
    public function setOrder(ShopOrder $o): self { $this->order = $o; return $this; }
    public function setPayoutId(?int $id): self { $this->payoutId = $id; return $this; }

    public function setIssuer(
        string $name,
        ?string $ico,
        ?string $dic,
        ?string $address,
        ?string $account,
        ?string $bankCode,
        ?string $registration,
        bool $vatPayer
    ): self {
        $this->issuerName = $name;
        $this->issuerIco = $ico;
        $this->issuerDic = $dic;
        $this->issuerAddress = $address;
        $this->issuerAccount = $account;
        $this->issuerBankCode = $bankCode;
        $this->issuerRegistration = $registration;
        $this->issuerVatPayer = $vatPayer;
        return $this;
    }

    public function setBuyer(
        string $name,
        ?string $ico,
        ?string $dic,
        ?string $address,
        ?string $email
    ): self {
        $this->buyerName = $name;
        $this->buyerIco = $ico;
        $this->buyerDic = $dic;
        $this->buyerAddress = $address;
        $this->buyerEmail = $email;
        return $this;
    }

    public function setDates(
        DateTimeImmutable $issuedAt,
        ?DateTimeImmutable $taxableSupplyDate,
        ?DateTimeImmutable $dueDate,
        ?DateTimeImmutable $paidAt
    ): self {
        $this->issuedAt = $issuedAt;
        $this->taxableSupplyDate = $taxableSupplyDate;
        $this->dueDate = $dueDate;
        $this->paidAt = $paidAt;
        return $this;
    }

    public function setAmounts(float $subtotal, float $vatRate, float $total, string $currency = 'CZK'): self
    {
        $this->subtotal = (string)$subtotal;
        $this->vatRate = (string)$vatRate;
        $this->vatAmount = (string)round($subtotal * $vatRate / 100, 2);
        $this->total = (string)$total;
        $this->currency = $currency;
        return $this;
    }

    public function setPayment(?string $vs, ?string $method): self
    {
        $this->variableSymbol = $vs;
        $this->paymentMethod = $method;
        return $this;
    }

    public function setItems(array $items): self { $this->itemsJson = $items; return $this; }
    public function setMetadata(?array $m): self { $this->metadataJson = $m; return $this; }

    public function attachPdf(string $path): self
    {
        $this->pdfPath = $path;
        $this->pdfGeneratedAt = new DateTimeImmutable();
        return $this;
    }

    public function markPaid(DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;
        return $this;
    }
}
