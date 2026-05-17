<?php
declare(strict_types=1);

namespace App\Model\Orm\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nette\Http\FileUpload;
use Nette\Utils\Random;

#[ORM\Entity]
#[ORM\Table(name: 'shop_product_photos')]
class ShopProductPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: ShopProduct::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ShopProduct $product;

    #[ORM\Column(type: 'string', length: 512)]
    private string $path;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'integer', name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'datetime_immutable', name: 'created_at')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getProduct(): ShopProduct { return $this->product; }
    public function getPath(): string { return $this->path; }
    public function getName(): string { return $this->name; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getFullPath(): string { return $this->path . $this->name; }

    public function setProduct(ShopProduct $product): self { $this->product = $product; return $this; }
    public function setSortOrder(int $order): self { $this->sortOrder = $order; return $this; }

    /**
     * Uloží nahraný soubor na disk a vyplní cestu / jméno.
     * Analogie k Photo::uploadAzylPhoto z existujícího kódu.
     */
    public function uploadProductPhoto(FileUpload $upload): self
    {
        if (!$upload->isOk() || !$upload->isImage()) {
            throw new \RuntimeException('Neplatný upload - není obrázek nebo chyba přenosu.');
        }
        $azylId = $this->product->getAzyl()->getId();
        // struktura: /upload/shop/{azylId}/{YYYY-MM}/
        $relativeDir = sprintf('/upload/shop/%d/%s/', $azylId, date('Y-m'));
        $absoluteDir = dirname(__DIR__, 4) . '/www' . $relativeDir;

        if (!is_dir($absoluteDir)) {
            @mkdir($absoluteDir, 0755, true);
        }

        $extension = strtolower(pathinfo($upload->getSanitizedName(), PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new \RuntimeException('Povoleny jsou pouze JPG, PNG a WEBP.');
        }
        $fileName = Random::generate(16) . '.' . $extension;

        $upload->move($absoluteDir . $fileName);

        $this->path = $relativeDir;
        $this->name = $fileName;
        return $this;
    }
}
