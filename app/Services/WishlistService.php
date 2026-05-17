<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\ShopProduct;
use App\Model\Orm\Repository\ShopProductRepository;
use Nette\Http\Session;
use Nette\Http\SessionSection;
use Nette\Security\User;

/**
 * Wishlist / "Uložit na později".
 *
 * Elegantní řešení problému "košík jen z 1 azylu":
 * Když zákazník chce produkt z jiného azylu, systém mu nabídne uložit ho do
 * wishlistu. Po dokončení aktuální objednávky mu nabídne přechod k wishlistu,
 * kde může produkty z wishlistu přesouvat do košíku (automaticky vyprázdní
 * košík a přidá jeden produkt z dalšího azylu).
 *
 * UX:
 *  1. User má v košíku zboží z Azylu A.
 *  2. Chce přidat produkt z Azylu B → dialog: "Máte v košíku Azyl A. Chcete
 *     tento produkt uložit na později?"
 *  3. User potvrdí → produkt do wishlist, pokračuje v nákupu A.
 *  4. Po checkoutu A má možnost "V uložených položkách máte 3 produkty z 2 azylů."
 *  5. Pokračuje wishlist → vybere produkty z Azylu B, přesune do košíku,
 *     objednává B. Atd.
 *
 * Výhoda: žádná konfuze, jedna objednávka = jeden azyl = jedna platba = jedna
 * QR = jedna doprava.
 */
class WishlistService
{
    private Session $session;
    private ShopProductRepository $productRepo;
    private User $user;

    public function __construct(Session $session, ShopProductRepository $productRepo, User $user)
    {
        $this->session = $session;
        $this->productRepo = $productRepo;
        $this->user = $user;
    }

    private function getSection(): SessionSection
    {
        $key = $this->user->isLoggedIn()
            ? 'shop_wishlist_' . $this->user->getId()
            : 'shop_wishlist_guest';
        return $this->session->getSection($key);
    }

    /**
     * @return int[] IDs produktů
     */
    public function getProductIds(): array
    {
        return $this->getSection()->get('ids') ?? [];
    }

    public function add(int $productId): void
    {
        $ids = $this->getProductIds();
        if (!in_array($productId, $ids, true)) {
            $ids[] = $productId;
            $this->getSection()->set('ids', $ids);
        }
    }

    public function remove(int $productId): void
    {
        $ids = array_values(array_diff($this->getProductIds(), [$productId]));
        $this->getSection()->set('ids', $ids);
    }

    public function isInWishlist(int $productId): bool
    {
        return in_array($productId, $this->getProductIds(), true);
    }

    public function count(): int
    {
        return count($this->getProductIds());
    }

    public function clear(): void
    {
        $this->getSection()->remove();
    }

    /**
     * Hydratované produkty seskupené podle azylu.
     * @return array<int, array{azyl: \App\Model\Orm\Entity\Azyl, products: ShopProduct[]}>
     */
    public function getGroupedByAzyl(): array
    {
        $grouped = [];
        foreach ($this->getProductIds() as $id) {
            $product = $this->productRepo->find($id);
            if ($product === null || !$product->isAvailable()) {
                continue;
            }
            $azylId = $product->getAzyl()->getId();
            if (!isset($grouped[$azylId])) {
                $grouped[$azylId] = [
                    'azyl' => $product->getAzyl(),
                    'products' => [],
                ];
            }
            $grouped[$azylId]['products'][] = $product;
        }
        return $grouped;
    }

    /**
     * Počet azylů v wishlistu (pro UI hint).
     */
    public function getAzylsCount(): int
    {
        return count($this->getGroupedByAzyl());
    }
}
