<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\ShopProduct;
use App\Model\Orm\Repository\ShopProductRepository;
use Nette\Http\Session;
use Nette\Http\SessionSection;
use Nette\Security\User;

/**
 * Session-based nákupní košík + integrace s WishlistService.
 *
 * Klíčové rozhodnutí: košík obsahuje zboží POUZE z jednoho azylu.
 * Pokud uživatel chce přidat produkt z jiného azylu, místo chyby ho
 * automaticky uložíme do wishlistu a vrátíme informativní result.
 *
 * Session klíč je per-user: shop_cart_{userId} pro přihlášené,
 * shop_cart_guest pro nepřihlášené — zabraňuje sdílení košíku
 * mezi různými účty ve stejném prohlížeči.
 */
class CartService
{
    private Session $session;
    private ShopProductRepository $productRepo;
    private WishlistService $wishlist;
    private User $user;

    public function __construct(
        Session $session,
        ShopProductRepository $productRepo,
        WishlistService $wishlist,
        User $user
    )
    {
        $this->session = $session;
        $this->productRepo = $productRepo;
        $this->wishlist = $wishlist;
        $this->user = $user;
    }

    private function getSection(): SessionSection
    {
        $key = $this->user->isLoggedIn()
            ? 'shop_cart_' . $this->user->getId()
            : 'shop_cart_guest';
        return $this->session->getSection($key);
    }

    public function getItems(): array
    {
        return $this->getSection()->get('items') ?? [];
    }

    public function getAzylId(): ?int
    {
        return $this->getSection()->get('azylId');
    }

    /**
     * Pokusí se přidat produkt do košíku. Místo výjimky vrací strukturovaný
     * result. Při konfliktu azylů automaticky uloží do wishlistu.
     */
    public function tryAddProduct(int $productId, int $quantity = 1): array
    {
        $product = $this->productRepo->find($productId);
        if ($product === null || !$product->isAvailable()) {
            return [
                'success' => false, 'reason' => 'unavailable',
                'currentAzyl' => null, 'newAzyl' => null,
                'addedToWishlist' => false,
                'message' => 'Produkt není dostupný.',
            ];
        }

        $currentAzylId = $this->getAzylId();
        if ($currentAzylId !== null && $currentAzylId !== $product->getAzyl()->getId()) {
            $this->wishlist->add($productId);
            return [
                'success' => false, 'reason' => 'different_azyl',
                'currentAzyl' => null, 'newAzyl' => $product->getAzyl(),
                'addedToWishlist' => true,
                'message' => sprintf(
                    'V košíku máte zboží z jiného azylu. Produkt "%s" jsme uložili do "Uložených položek", '
                    . 'kam se můžete vrátit po dokončení aktuální objednávky.',
                    $product->getName()
                ),
            ];
        }

        try {
            $this->addProduct($productId, $quantity);
            return [
                'success' => true, 'reason' => null,
                'currentAzyl' => $product->getAzyl(), 'newAzyl' => $product->getAzyl(),
                'addedToWishlist' => false,
                'message' => 'Produkt přidán do košíku.',
            ];
        } catch (\RuntimeException $e) {
            return [
                'success' => false, 'reason' => 'stock',
                'currentAzyl' => $product->getAzyl(), 'newAzyl' => $product->getAzyl(),
                'addedToWishlist' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function addProduct(int $productId, int $quantity = 1): void
    {
        if ($quantity < 1) return;

        $product = $this->productRepo->find($productId);
        if ($product === null || !$product->isAvailable()) {
            throw new \RuntimeException('Produkt není dostupný.');
        }

        $currentAzylId = $this->getAzylId();
        if ($currentAzylId !== null && $currentAzylId !== $product->getAzyl()->getId()) {
            throw new \RuntimeException(
                'V košíku máte již zboží z jiného azylu. ' .
                'Dokončete nejprve tu objednávku, nebo košík vyprázdněte.'
            );
        }

        $items = $this->getItems();
        $newQty = ($items[$productId] ?? 0) + $quantity;

        if (!$product->isUnlimitedStock() && $newQty > $product->getStock()) {
            throw new \RuntimeException(sprintf(
                'Maximálně mohu nabídnout %d ks produktu "%s".',
                $product->getStock(), $product->getName()
            ));
        }

        $items[$productId] = $newQty;
        $this->getSection()->set('items', $items);
        $this->getSection()->set('azylId', $product->getAzyl()->getId());
    }

    public function setQuantity(int $productId, int $quantity): void
    {
        if ($quantity < 1) { $this->removeProduct($productId); return; }
        $product = $this->productRepo->find($productId);
        if ($product === null) { $this->removeProduct($productId); return; }
        if (!$product->isUnlimitedStock() && $quantity > $product->getStock()) {
            $quantity = $product->getStock();
        }
        $items = $this->getItems();
        $items[$productId] = $quantity;
        $this->getSection()->set('items', $items);
    }

    public function removeProduct(int $productId): void
    {
        $items = $this->getItems();
        unset($items[$productId]);
        $this->getSection()->set('items', $items);
        if (empty($items)) $this->clear();
    }

    public function clear(): void { $this->getSection()->remove(); }
    public function getItemCount(): int { return array_sum($this->getItems()); }

    public function getHydratedItems(): array
    {
        $result = [];
        foreach ($this->getItems() as $productId => $qty) {
            $product = $this->productRepo->find($productId);
            if ($product === null) continue;
            $result[] = [
                'product' => $product,
                'quantity' => $qty,
                'subtotal' => round($product->getPrice() * $qty, 2),
            ];
        }
        return $result;
    }

    public function getSubtotal(): float
    {
        $total = 0.0;
        foreach ($this->getHydratedItems() as $item) $total += $item['subtotal'];
        return $total;
    }

    public function getShippingFee(): float
    {
        $azylId = $this->getAzylId();
        if ($azylId === null) return 0.0;
        $product = null;
        foreach ($this->getItems() as $productId => $_) {
            $product = $this->productRepo->find($productId);
            if ($product !== null) break;
        }
        if ($product === null) return 0.0;
        return $product->getAzyl()->getShippingFee() ?? 0.0;
    }

    public function getPackagingFee(): float
    {
        $azylId = $this->getAzylId();
        if ($azylId === null) return 0.0;
        $product = null;
        foreach ($this->getItems() as $productId => $_) {
            $product = $this->productRepo->find($productId);
            if ($product !== null) break;
        }
        if ($product === null) return 0.0;
        return $product->getAzyl()->getPackagingFee() ?? 0.0;
    }

    public function getTotal(): float
    {
        return round($this->getSubtotal() + $this->getShippingFee() + $this->getPackagingFee(), 2);
    }

    public function isEmpty(): bool { return empty($this->getItems()); }
}
