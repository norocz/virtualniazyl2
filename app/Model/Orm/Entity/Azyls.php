<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use App\Model\Orm\Entity\Conversations;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'azyls')]
#[\AllowDynamicProperties] //todo: tohle se musí vyřešit otázka je proč se to tu objevilo.
class Azyl
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int $id;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $azylName;

    #[ORM\Column(type: 'string', length: 3000, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $bankAccount;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $bankCode;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $bankSpecificCode;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $phoneNumber;

    #[ORM\OneToMany(mappedBy: "azyl", targetEntity: Animal::class)]
    private ?Collection $animals;

    #[ORM\OneToMany(mappedBy: "azyl", targetEntity: Adoption::class)]
    private ?Collection $adoptions;

    #[ORM\OneToMany(mappedBy: "payments", targetEntity: Payments::class)]
    private ?Collection $payments;

    #[ORM\OneToMany(mappedBy: "collections", targetEntity: Collections::class)]
    private ?Collection $collections;

    #[ORM\OneToMany(mappedBy: "azyl", targetEntity: News::class)]
    private ?Collection $news = null;


    #[ORM\Column(type: 'integer', length: 10, nullable: true)]
    private ?int $mainPhoto = null;

    #[ORM\OneToMany(mappedBy: "azyl", targetEntity: "Photo")]
    public ?Collection $photos;

    #[ORM\OneToMany(mappedBy: 'reviewer', targetEntity: UsersRatings::class)]
    private ?Collection $reviewerRatings;

    #[ORM\Column(type: 'string', length: 34 ,nullable: true)]
    private ?string $random;

    #[ORM\OneToMany(mappedBy: "azyl", targetEntity: Contracts::class)]
    private ?Collection $contracts;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $messageAddress;

    #[ORM\OneToMany(mappedBy: "azyl", targetEntity: Messages::class)]
    public ?Collection $sentMessages;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $city;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $web;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $email;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $ico;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $shortDescription;

    #[ORM\OneToMany(mappedBy: "azyl", targetEntity: Conversations::class, fetch: 'EXTRA_LAZY')]
    private ?Collection $conversations = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true, name: 'shipping_fee')]
    private ?string $shippingFee = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true, name: 'packaging_fee')]
    private ?string $packagingFee = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true, name: 'shop_fee_percent')]
    private ?string $shopFeePercent = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true, name: 'shop_theme_color')]
    private ?string $shopThemeColor = null;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true, name: 'house_number')]
    private ?string $houseNumber = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true, name: 'zip_code')]
    private ?string $zipCode = null;

    #[ORM\Column(type: 'string', length: 5, nullable: true, name: 'country_code', options: ['default' => 'CZ'])]
    private ?string $countryCode = 'CZ';

    public function __toString(): string
    {
        return (string)$this->id;  // nebo jiný identifikátor entity Azyl
    }
    public function __construct()
    {
        $this->animals = new ArrayCollection();
        $this->adoptions = new ArrayCollection();
        $this->news = new ArrayCollection();
        $this->photos = new ArrayCollection();
        $this->reviewerRatings = new ArrayCollection();

    }
    public function toArray(): array
    {
        return ['azylName' => $this->azylName
        , 'description' => $this->description
        , 'bankAccount' => $this->bankAccount
        , 'bankCode' => $this->bankCode
        , 'bankSpecificCode' => $this->bankSpecificCode
        , 'phoneNumber' => $this->phoneNumber
        , 'mainPhoto' => $this->mainPhoto->toArray()]
            ;
    }

    public function getReviewerRatings(): Collection
    {
        return $this->reviewerRatings;
    }

    public function getAzylName(): ?string
    {
        return $this->azylName;
    }

    /**
     * @return mixed
     */
    public function getAnimals() : Collection
    {
        return $this->animals;
    }

    public function setAzylName(string $azylName): Azyl
    {
        $this->azylName = $azylName;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): Azyl
    {
        $this->description = $description;
        return $this;
    }

    public function getBankAccount(): ?string
    {
        return $this->bankAccount;
    }

    public function setBankAccount(?string $bankAccount): Azyl
    {
        $this->bankAccount = $bankAccount;
        return $this;
    }

    public function getBankCode(): ?string
    {
        return $this->bankCode;
    }

    public function setBankCode(?string $bankCode): Azyl
    {
        $this->bankCode = $bankCode;
        return $this;
    }

    public function getBankSpecificCode(): ?string
    {
        return $this->bankSpecificCode;
    }

    public function setBankSpecificCode(?string $bankSpecificCode): Azyl
    {
        $this->bankSpecificCode = $bankSpecificCode;
        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): Azyl
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getMainPhoto(): ?int
    {
        return $this->mainPhoto;
    }

    public function setMainPhoto(?int $mainPhoto): Azyl
    {
        $this->mainPhoto = $mainPhoto;
        return $this;
    }

    public function getNews(): ?Collection
    {
        return $this->news;
    }

    public function getAzylNews(): ?Collection
    {
        return $this->news->matching(Criteria::create()
            ->where(Criteria::expr()->eq("deleted", false))
            ->andWhere(Criteria::expr()->lte("visibleFrom", new \DateTimeImmutable('now')))
            ->orderBy(["createdAt" => 'DESC']));
    }

    public function getAdoptions(): ?Collection
    {
        return $this->adoptions;
    }

    public function setAdoptions(?Collection $adoptions): Azyl
    {
        $this->adoptions = $adoptions;
        return $this;
    }

    public function getPayments(): ?Collection
    {
        return $this->payments;
    }

    public function setPayments(?Collection $payments): Azyl
    {
        $this->payments = $payments;
        return $this;
    }

    public function getCollections(): ?Collection
    {
        return $this->collections;
    }

    public function setCollections(?Collection $collections): Azyl
    {
        $this->collections = $collections;
        return $this;
    }

    public function getPhotos(): ?Collection
    {
        return $this->photos;
    }

    public function setPhotos(?Collection $photos): Azyl
    {
        $this->photos = $photos;
        return $this;
    }

    public function getRandom(): ?string
    {
        return $this->random;
    }

    public function setRandom(?string $random): Azyl
    {
        $this->random = $random;
        return $this;
    }

    public function getContracts(): ?Collection
    {
        return $this->contracts;
    }

    public function setContracts(?Collection $contracts): Azyl
    {
        $this->contracts = $contracts;
        return $this;
    }

    public function getMessageAddress(): ?string
    {
        return $this->messageAddress;
    }

    public function setMessageAddress(?string $messageAddress): Azyl
    {
        $this->messageAddress = $messageAddress;
        return $this;
    }

    public function getCity(): ?int
    {
        return $this->city;
    }

    public function setCity(?int $city): Azyl
    {
        $this->city = $city;
        return $this;
    }

    public function getWeb(): ?string
    {
        return $this->web;
    }

    public function setWeb(?string $web): Azyl
    {
        $this->web = $web;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): Azyl
    {
        $this->email = $email;
        return $this;
    }

    public function getIco(): ?string
    {
        return $this->ico;
    }

    public function setIco(?string $ico): Azyl
    {
        $this->ico = $ico;
        return $this;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): Azyl
    {
        $this->shortDescription = $shortDescription;
        return $this;
    }

    public function getShippingFee(): ?float
    {
        return $this->shippingFee !== null ? (float) $this->shippingFee : null;
    }

    public function setShippingFee(?float $shippingFee): Azyl
    {
        $this->shippingFee = $shippingFee !== null ? (string) $shippingFee : null;
        return $this;
    }

    public function getPackagingFee(): ?float
    {
        return $this->packagingFee !== null ? (float) $this->packagingFee : null;
    }

    public function setPackagingFee(?float $packagingFee): Azyl
    {
        $this->packagingFee = $packagingFee !== null ? (string) $packagingFee : null;
        return $this;
    }

    public function getShopFeePercent(): ?float
    {
        return $this->shopFeePercent !== null ? (float) $this->shopFeePercent : null;
    }

    public function setShopFeePercent(?float $shopFeePercent): Azyl
    {
        $this->shopFeePercent = $shopFeePercent !== null ? (string) $shopFeePercent : null;
        return $this;
    }

    public function getShopThemeColor(): ?string
    {
        return $this->shopThemeColor;
    }

    public function setShopThemeColor(?string $shopThemeColor): Azyl
    {
        $this->shopThemeColor = $shopThemeColor;
        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude !== null ? (float)$this->latitude : null;
    }

    public function setLatitude(?float $latitude): self
    {
        $this->latitude = $latitude !== null ? (string)$latitude : null;
        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude !== null ? (float)$this->longitude : null;
    }

    public function setLongitude(?float $longitude): self
    {
        $this->longitude = $longitude !== null ? (string)$longitude : null;
        return $this;
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): self
    {
        $this->street = $street;
        return $this;
    }

    public function getHouseNumber(): ?string
    {
        return $this->houseNumber;
    }

    public function setHouseNumber(?string $houseNumber): self
    {
        $this->houseNumber = $houseNumber;
        return $this;
    }

    public function getZipCode(): ?string
    {
        return $this->zipCode;
    }

    public function setZipCode(?string $zipCode): self
    {
        $this->zipCode = $zipCode;
        return $this;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode ?? 'CZ';
    }

    public function setCountryCode(string $countryCode): self
    {
        $this->countryCode = $countryCode;
        return $this;
    }

    public function getFullAddress(): ?string
    {
        $parts = array_filter([
            trim(($this->street ?? '') . ' ' . ($this->houseNumber ?? '')),
            $this->zipCode,
        ]);
        return !empty($parts) ? implode(', ', $parts) : null;
    }
}