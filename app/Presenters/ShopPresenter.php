<?php
declare(strict_types=1);

namespace App\Presenters;

use App\Forms\ShopCheckoutFormFactory;
use App\Model\Orm\Entity\ShopOrder;
use App\Model\Orm\Repository\AzylRepository;
use App\Model\Orm\Repository\ShopOrderRepository;
use App\Model\Orm\Repository\ShopProductRepository;
use App\Model\Services\Menu;
use App\Services\CartService;
use App\Services\ShopQrService;
use App\Services\ShopService;
use App\Services\SystemSettingsReader;
use Nette\Application\UI\Form;
use Contributte\Application\UI\BasePresenter;

/**
 * Frontend eshopu pro nakupování.
 *
 * Tento presenter jsem navrhl tak, aby byl tenký - deleguje vše
 * na služby, sám je jen "UI lepidlo".
 */
class ShopPresenter extends BasePresenter
{
    public function __construct(
        private readonly ShopProductRepository $productRepo,
        private readonly ShopOrderRepository $orderRepo,
        private readonly AzylRepository $azylRepo,
        private readonly CartService $cart,
        private readonly ShopService $shopService,
        private readonly ShopQrService $qrService,
        private readonly ShopCheckoutFormFactory $checkoutFormFactory,
        private readonly SystemSettingsReader $settings,
    )
    {
        parent::__construct();
    }

    // ============================================
    // Katalog
    // ============================================
    public function renderDefault(?string $q = null, ?string $category = null, ?int $azyl = null): void
    {
        if ((int)$this->settings->get('shop.enabled', 1) !== 1) {
            $this->template->products = [];
            $this->flashMessage('Eshop je momentálně vypnutý.', 'alert-info');
        } elseif ($azyl !== null) {
            $azylEntity = $this->azylRepo->find($azyl);
            if (!$azylEntity) {
                $this->error('Azyl nenalezen.', 404);
            }
            $this->template->products = $this->productRepo->findByAzyl($azylEntity, true);
            $this->template->azylFilter = $azylEntity;
        } elseif (!empty($q)) {
            $this->template->products = $this->productRepo->search($q);
        } else {
            $this->template->products = $this->productRepo->findAvailable($category);
        }

        $this->template->searchQuery = $q;
        $this->template->selectedCategory = $category;
        $this->template->selectedAzylId = $azyl;
        $this->template->azylList = $this->azylRepo->findAll();
        $this->template->categories = $this->fetchCategories();
        $this->template->cartItemCount = $this->cart->getItemCount();
        $this->template->feePercent = (float)$this->settings->get('shop.fee_percent', 5.0);
    }

    // ============================================
    // Detail produktu
    // ============================================
    public function renderProduct(int $id): void
    {
        $product = $this->productRepo->find($id);
        if ($product === null || !$product->isAvailable()) {
            $this->error('Produkt nenalezen nebo není dostupný.');
        }
        $this->template->product = $product;
        $this->template->feePercent = (float)$this->settings->get('shop.fee_percent', 5.0);
        $this->template->cartItemCount = $this->cart->getItemCount();
    }

    public function createComponentAddToCartForm(): Form
    {
        $form = new Form;
        $form->addInteger('quantity', 'Množství')
            ->setDefaultValue(1)
            ->addRule(Form::Min, 'Min 1', 1)
            ->setHtmlAttribute('class', 'form-control');
        $form->addSubmit('add', 'Přidat do košíku');

        $form->onSuccess[] = function (Form $form, \stdClass $values): void {
            $productId = (int)$this->getParameter('id');
            try {
                $this->cart->addProduct($productId, $values->quantity);
                $this->flashMessage('Přidáno do košíku.', 'alert-success');
                $this->redirect('cart');
            } catch (\Throwable $e) {
                $form->addError($e->getMessage());
            }
        };

        return $form;
    }

    // ============================================
    // Košík
    // ============================================
    public function renderCart(): void
    {
        $this->template->cart = $this->cart;
        $this->template->cartItemCount = $this->cart->getItemCount();
        $this->template->shippingCost = (float)$this->settings->get('shop.default_shipping_cost', 99);
        $this->template->feePercent = (float)$this->settings->get('shop.fee_percent', 5.0);
    }

    public function handleRemoveFromCart(int $id): void
    {
        $this->cart->removeProduct($id);
        $this->flashMessage('Odstraněno z košíku.', 'alert-info');
        $this->redirect('cart');
    }

    public function handleUpdateCartItem(int $id): void
    {
        $quantity = (int)$this->getHttpRequest()->getPost('quantity', 0);
        if ($quantity <= 0) {
            $this->cart->removeProduct($id);
            $this->flashMessage('Položka odstraněna z košíku.', 'alert-info');
        } else {
            $this->cart->setQuantity($id, $quantity);
        }
        $this->redirect('cart');
    }

    // ============================================
    // Checkout
    // ============================================
    public function renderCheckout(): void
    {
        if ($this->cart->isEmpty()) {
            $this->flashMessage('Košík je prázdný.', 'alert-warning');
            $this->redirect('default');
        }

        if (!$this->getUser()->isLoggedIn()) {
            $this->getSession('back')->set('backUrl', 'Shop:checkout');
            $this->flashMessage('Pro dokončení objednávky se prosím přihlaste nebo zaregistrujte.', 'alert-info');
            $this->redirect('Home:signIn');
        }

        $this->template->cart = $this->cart;
        $this->template->cartItemCount = $this->cart->getItemCount();
        $this->template->shippingCost = (float)$this->settings->get('shop.default_shipping_cost', 99);
    }

    public function createComponentCheckoutForm(): Form
    {
        $form = $this->checkoutFormFactory->create();

        // Pokud je přihlášený, předvyplníme
        if ($this->getUser()->isLoggedIn()) {
            $userData = $this->getUser()->getIdentity()->getData();
            $user = $userData['User'] ?? null;
            if ($user !== null) {
                $form->setDefaults([
                    'buyerName' => trim($user->getFirstName() . ' ' . $user->getLastName()),
                    'buyerEmail' => $user->getEmail(),
                ]);
            }
        }

        $form->onSuccess[] = [$this, 'checkoutFormSucceeded'];
        return $form;
    }

    public function checkoutFormSucceeded(Form $form, \stdClass $values): void
    {
        if ($this->cart->isEmpty()) {
            $form->addError('Košík je prázdný.');
            return;
        }

        try {
            $user = $this->getUser()->isLoggedIn()
                ? $this->getUser()->getIdentity()->getData()['User'] ?? null
                : null;

            $order = $this->shopService->createOrder(
                $this->cart->getHydratedItems(),
                [
                    'name' => $values->buyerName,
                    'email' => $values->buyerEmail,
                    'phone' => $values->buyerPhone ?: null,
                ],
                [
                    'street' => $values->deliveryStreet,
                    'houseNumber' => $values->deliveryHouseNumber,
                    'city' => $values->deliveryCity,
                    'psc' => $values->deliveryPsc,
                    'note' => $values->deliveryNote ?: null,
                ],
                $user,
                'cs'
            );

            $this->cart->clear();
            $this->redirect('orderDetail', $order->getOrderNumber());
        } catch (\Throwable $e) {
            $form->addError('Chyba: ' . $e->getMessage());
        }
    }

    // ============================================
    // Detail objednávky
    // ============================================
    public function renderOrderDetail(string $id): void
    {
        $order = $this->orderRepo->findByOrderNumber($id);
        if ($order === null) {
            $this->error('Objednávka nenalezena.');
        }

        // Pokud je přihlášený, ověříme že je majitel (nebo admin)
        if ($this->getUser()->isLoggedIn()
            && !$this->getUser()->isInRole('admin')
            && !$this->getUser()->isInRole('superadmin')
            && $order->getUser() !== null
            && $order->getUser()->getId() !== (int)$this->getUser()->getId()) {
            $this->error('Nemáte oprávnění.', 403);
        }

        $this->template->order = $order;
        $this->template->qrImage = $this->qrService->generateQrForOrder($order);
        $this->template->paymentDetails = $this->qrService->getPaymentDetails($order);
        $this->template->baseUrl = $this->getHttpRequest()->getUrl()->getBaseUrl();
    }

    protected function beforeRender(): void
    {
        parent::beforeRender();
        $this->template->mainMenuItems = (new Menu())->getMenu();
        $this->template->cartItemCount = $this->cart->getItemCount();
        $this->template->addFilter('safeHtml', function (string $html): string {
            $allowed = ['b', 'i', 'a', 'p', 'br', 'ul', 'ol', 'li', 'strong', 'em'];
            $html = strip_tags($html, '<' . implode('><', $allowed) . '>');
            return preg_replace_callback('/<a\s+([^>]+)>/i', function ($m) {
                if (preg_match('/href=["\'](.*?)["\']/', $m[1], $href)) {
                    return '<a href="' . htmlspecialchars($href[1], ENT_QUOTES) . '">';
                }
                return '<a>';
            }, $html);
        });
    }

    // ============================================
    // Template mapping (šablony jsou v Home/ adresáři)
    // ============================================
    public function formatTemplateFiles(): array
    {
        $map = [
            'default'     => 'shop',
            'product'     => 'product',
            'cart'        => 'cart',
            'checkout'    => 'checkout',
            'orderDetail' => 'orderDetail',
            'wishlist'    => 'wishlist',
        ];
        $view = $this->getView();
        $file = $map[$view] ?? $view;
        return [__DIR__ . '/templates/Home/' . $file . '.latte'];
    }

    // ============================================
    // Helpery
    // ============================================
    private function fetchCategories(): array
    {
        return $this->productRepo->fetchCategories();
    }
}
