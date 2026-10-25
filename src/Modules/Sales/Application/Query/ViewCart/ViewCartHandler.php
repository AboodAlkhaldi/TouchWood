<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewCart;

use Modules\Access\Public\Enums\AccountType;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Sales\Application\Cart\CartBuyer;
use Modules\Sales\Application\Order\SizeRecords;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Public\Enums\CartLineProblem;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * **The cart** (sales.md §1.1, §3 ViewCart) — a customer's or a guest's in a store, read only. Each line as it
 * stands now: its card, today's prices, and whether it can be ordered — the checkout's own rules (§1.2 step 2)
 * marked on each line instead of refusing, so the shopper sees what to change before checking out. A cart
 * nobody made yet is an empty one.
 */
final readonly class ViewCartHandler
{
    public const string PERMISSION = SalesPermissions::CART_MANAGE;

    public function __construct(
        private CartBuyer $buyer,
        private Authorizer $authorizer,
        private Carts $carts,
        private CatalogApi $catalog,
        private PricingApi $pricing,
        private InventoryApi $inventory,
        private SizeRecords $sizes,
        private PlatformApi $platform,
        private B2BApi $b2b,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute
     */
    public function handle(ViewCart $query): CartView
    {
        $locale = in_array($query->locale, ['ar', 'en'], true) ? $query->locale : throw new InvalidSalesAttribute('locale', 'ar or en');
        [$store, $customerId, $guestId, $accountType] = $this->buyer->of($query->storeId);
        $this->authorizer->authorize($customerId === null ? SalesPermissions::GUEST_CART_MANAGE : self::PERMISSION, PermissionScope::store($store));
        $currency = $this->platform->store($store)->currencyCode ?? 'SAR';
        $exponent = $this->platform->currency($currency)->exponent ?? 2;
        $cart = $customerId === null && $guestId === null ? null : $this->carts->find($store, $customerId, $guestId);

        if ($cart === null || $cart->lines === []) {
            return new CartView($store->value, [], $cart?->couponCode, $cart->notice ?? [], null, $exponent);
        }

        $company = $customerId !== null && $accountType === AccountType::Company && $this->b2b->isApproved($customerId, $store->value);
        $viewer = $customerId === null ? ShopperKind::Guest : ($accountType === AccountType::Company ? ShopperKind::Company : ShopperKind::Individual);
        $variantIds = array_values(array_unique(array_map(static fn ($line): string => $line->variantId, $cart->lines)));
        $variants = $this->catalog->storeVariants($store, $variantIds);
        $records = $this->sizes->of($variantIds);
        $productIds = array_values(array_unique(array_map(static fn (array $record): string => $record['productId'], $records)));
        $open = array_flip($this->catalog->productsOpenTo($store, $viewer, $productIds));
        $cards = $this->catalog->productCards($store, $locale, $productIds);
        $photos = $this->platform->mediaUrlsOf(array_values(array_filter(array_map(static fn ($card): ?string => $card->photoMediaId, $cards))));
        $stock = $this->inventory->stock($store, $variantIds);
        $prices = $this->pricing->prices($store, array_map(static fn ($line): CartLineDto => new CartLineDto($line->variantId, $line->mode, $line->quantity), $cart->lines));
        $priced = [];

        foreach ($prices->lines as $price) {
            $priced[$price->variantId.'|'.$price->mode->value] = $price;
        }

        $pieces = [];

        foreach ($cart->lines as $line) {
            $pieces[$line->variantId] = ($pieces[$line->variantId] ?? 0) + $line->quantity;
        }

        $lines = [];
        $subtotal = null;

        foreach ($cart->lines as $line) {
            $variant = $variants[$line->variantId] ?? null;
            $record = $records[$line->variantId] ?? null;
            $price = $priced[$line->variantId.'|'.$line->mode->value] ?? null;
            $wholesale = $line->mode === SaleMode::Wholesale;
            [$least, $most] = $wholesale ? [$variant->wholesaleMinimum ?? 1, $variant?->wholesaleMaximum] : [$variant->retailMinimum ?? 1, $variant?->retailMaximum];
            $held = $stock[$line->variantId] ?? null;

            $problem = match (true) {
                $variant === null || $record === null || ! $variant->isActive || ! $variant->orderable || $variant->notAvailableNow
                    || ! in_array($line->mode, $variant->saleModes, true) => CartLineProblem::NotAvailable,
                ($wholesale && ! $company) || ! isset($open[$record['productId']]) => CartLineProblem::NotForYourAccount,
                $line->quantity < $least => CartLineProblem::BelowMinimum,
                $most !== null && $line->quantity > $most => CartLineProblem::AboveMaximum,
                $held === null || ! $held->orderable || ($held->available !== null && $held->available < $pieces[$line->variantId]) => CartLineProblem::OutOfStock,
                $price === null => CartLineProblem::NotPriced,
                default => null,
            };

            // A line that can be ordered has its price (an unpriced one is NOT_PRICED).
            if ($problem === null) {
                $subtotal = ($subtotal ?? Money::zero($price->total->currencyCode))->add($price->total);
            }

            $card = $record === null ? null : ($cards[$record['productId']] ?? null);
            $lines[] = new CartLineView(
                $line->variantId, $record['productId'] ?? '', $card === null ? ($record === null ? '' : ($locale === 'ar' ? $record['nameAr'] : $record['nameEn'])) : $card->name,
                $card?->slug, $card?->photoMediaId === null ? null : ($photos[strtolower($card->photoMediaId)]->variants ?? null),
                $record['code'] ?? '', $record['values'] ?? [], $line->mode->value, $line->quantity,
                $price?->unit, $price?->listUnit, $price?->total, $problem, $least, $most,
            );
        }

        return new CartView($store->value, $lines, $cart->couponCode, $cart->notice, $subtotal, $exponent);
    }
}
