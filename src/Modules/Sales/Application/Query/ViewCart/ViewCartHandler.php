<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewCart;

use Modules\Access\Public\Contracts\AccessApi;
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
use Modules\Sales\Domain\Model\Cart;
use Modules\Sales\Domain\Model\RemovedLine;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Public\Enums\CartLineProblem;
use Modules\Sales\Public\Enums\CartNoticeReason;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * **The cart** (sales.md §1.1, §3 ViewCart) — a customer's or a guest's in a store, read only. Each line as it
 * stands now: its card, today's prices, and whether it can be ordered — the checkout's own rules (§1.2 step 2)
 * marked on each line instead of refusing, so the shopper sees what to change before checking out. A guest
 * sees what either kind of account sees and may hold wholesale lines (§1.1): only what is true for any
 * account is marked on them, the account's own rules waiting for the sign-in. A cart nobody made yet is an
 * empty one.
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
        private AccessApi $access,
        private B2BApi $b2b,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute
     */
    public function handle(ViewCart $query): CartView
    {
        [$store, $customerId, $guestId, $accountType] = $this->buyer->of($query->storeId);
        $this->authorizer->authorize($customerId === null ? SalesPermissions::GUEST_CART_MANAGE : self::PERMISSION, PermissionScope::store($store));
        $locale = in_array($query->locale, ['ar', 'en'], true) ? $query->locale : throw new InvalidSalesAttribute('locale', 'ar or en');
        $company = $customerId !== null && $accountType === AccountType::Company && $this->b2b->isApproved($customerId, $store->value);
        // Whether checkout would take this account at all (§1.2 step 1): a guest signs in first.
        $mayOrder = $customerId !== null && $this->access->customerMayOrder($customerId) && ($accountType !== AccountType::Company || $company);
        $cart = $customerId === null && $guestId === null ? null : $this->carts->find($store, $customerId, $guestId);
        $records = $cart === null ? [] : $this->sizes->of(array_values(array_unique([
            ...array_map(static fn ($line): string => $line->variantId, $cart->lines),
            ...array_map(static fn (RemovedLine $line): string => $line->variantId, $cart->notice),
        ])));
        $notice = $cart === null ? [] : self::notice($cart, $records, $locale);

        if ($cart === null || $cart->lines === []) {
            $currency = $this->platform->store($store)?->currencyCode;

            return new CartView($store->value, [], $mayOrder, $cart?->couponCode, $notice, null, $currency === null ? 2 : ($this->platform->currency($currency)->exponent ?? 2));
        }

        $viewer = $customerId === null ? ShopperKind::Guest : ($accountType === AccountType::Company ? ShopperKind::Company : ShopperKind::Individual);
        $variantIds = array_values(array_unique(array_map(static fn ($line): string => $line->variantId, $cart->lines)));
        $variants = $this->catalog->storeVariants($store, $variantIds);
        $productIds = array_values(array_unique(array_map(static fn (array $record): string => $record['productId'], array_intersect_key($records, array_flip($variantIds)))));
        $open = array_flip($this->catalog->productsOpenTo($store, $viewer, $productIds));
        $cards = $this->catalog->productCards($store, $locale, $productIds);
        $photoIds = array_values(array_filter(array_map(static fn ($card): ?string => $card->photoMediaId, $cards)));
        $photos = $photoIds === [] ? [] : $this->platform->mediaUrlsOf($photoIds);
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
            $record = $records[$line->variantId] ?? null;

            // A size gone meanwhile (its line goes with it): nothing to show.
            if ($record === null) {
                continue;
            }

            $variant = $variants[$line->variantId] ?? null;
            $price = $priced[$line->variantId.'|'.$line->mode->value] ?? null;
            $wholesale = $line->mode === SaleMode::Wholesale;
            [$least, $most] = $wholesale ? [$variant->wholesaleMinimum ?? 1, $variant?->wholesaleMaximum] : [$variant->retailMinimum ?? 1, $variant?->retailMaximum];
            $held = $stock[$line->variantId] ?? null;

            $problem = match (true) {
                $variant === null || ! $variant->isActive || ! $variant->orderable || $variant->notAvailableNow
                    || ! in_array($line->mode, $variant->saleModes, true) => CartLineProblem::NotAvailable,
                // Wholesale for a signed-in account that is not an approved company; a guest's waits for the sign-in.
                ($wholesale && $customerId !== null && ! $company) || ! isset($open[$record['productId']]) => CartLineProblem::NotForYourAccount,
                $line->quantity < $least => CartLineProblem::BelowMinimum,
                $most !== null && $line->quantity > $most => CartLineProblem::AboveMaximum,
                $held === null || ! $held->orderable || ($held->available !== null && $held->available < $pieces[$line->variantId]) => CartLineProblem::OutOfStock,
                $price === null => CartLineProblem::NotPriced,
                default => null,
            };

            // A line that can be ordered has its price (an unpriced one is NOT_PRICED).
            if ($problem === null) {
                $subtotal = ($subtotal ?? Money::zero($prices->currencyCode))->add($price->total);
            }

            $card = $cards[$record['productId']] ?? null;
            $lines[] = new CartLineView(
                $line->variantId, $record['productId'], $card === null ? self::name($record, $locale) : $card->name,
                $card?->slug, $card?->photoMediaId === null ? null : ($photos[strtolower($card->photoMediaId)]->variants ?? null),
                $record['code'], $record['values'], $line->mode->value, $line->quantity,
                $price?->unit, $price?->listUnit, $price?->total, $problem, $least, $most,
            );
        }

        return new CartView($store->value, $lines, $mayOrder, $cart->couponCode, $notice, $subtotal, $this->platform->currency($prices->currencyCode)->exponent ?? 2);
    }

    /**
     * @param  array<string, array{productId: string, nameAr: string, nameEn: string, values: list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>, code: string}>  $records
     * @return list<CartNoticeLine>
     */
    private static function notice(Cart $cart, array $records, string $locale): array
    {
        $lines = [];

        foreach ($cart->notice as $removed) {
            $record = $records[$removed->variantId] ?? null;
            $reason = CartNoticeReason::tryFrom($removed->reason);

            if ($record !== null && $reason !== null) {
                $lines[] = new CartNoticeLine($removed->variantId, self::name($record, $locale), $record['code'], $record['values'], $removed->mode->value, $removed->quantity, $reason);
            }
        }

        return $lines;
    }

    /**
     * @param  array{nameAr: string, nameEn: string}  $record
     */
    private static function name(array $record, string $locale): string
    {
        return $locale === 'ar' ? $record['nameAr'] : $record['nameEn'];
    }
}
