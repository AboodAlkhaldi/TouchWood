<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Quote;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Enums\Audience as PromotionsAudience;
use Modules\Sales\Domain\Exception\AddressNotUsable;
use Modules\Sales\Domain\Exception\MayNotOrder;
use Modules\Sales\Domain\Exception\NotForYourAccount;
use Modules\Sales\Domain\Exception\NotOrderable;
use Modules\Sales\Public\Enums\OrderAudience;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * The rules of a quote that can change in 15 minutes (sales.md §1.2 steps 1–3, 8, 11): who may order,
 * what may be bought, the address, the gift, the ways to pay. Checked on the cart's lines at checkout
 * and again on the quote's lines when the order is placed (§1.3 step 3). Reads only.
 */
final readonly class QuoteRules
{
    public function __construct(
        private PlatformApi $platform,
        private AccessApi $access,
        private B2BApi $b2b,
        private CatalogApi $catalog,
        private InventoryApi $inventory,
        private PromotionsApi $promotions,
    ) {}

    /**
     * The store on, the account may order, and a company approved here: `COMPANY`; an individual `PUBLIC`.
     *
     * @throws MayNotOrder
     */
    public function audience(StoreId $store, string $customerId): OrderAudience
    {
        if (! ($this->platform->store($store)->isActive ?? false) || ! $this->access->customerMayOrder($customerId)) {
            throw new MayNotOrder;
        }

        if ($this->access->customer($customerId)?->accountType !== AccountType::Company) {
            return OrderAudience::Public;
        }

        return $this->b2b->isApproved($customerId, $store->value) ? OrderAudience::Company : throw new MayNotOrder;
    }

    /**
     * Each line sold here in its mode, within its limits, open to the account, in stock — the pieces of a
     * size counted over both its modes; a wholesale line only for a company. An edit's stock is the hold's
     * to check (§1.7 step 7): `$stock` false leaves it out.
     *
     * @param  list<CartLineDto>  $lines
     *
     * @throws NotForYourAccount|NotOrderable
     */
    public function lines(StoreId $store, array $lines, OrderAudience $audience, bool $stock = true): void
    {
        if ($lines === []) {
            throw new NotOrderable;
        }

        $variantIds = array_values(array_unique(array_map(static fn (CartLineDto $line): string => $line->variantId, $lines)));
        $variants = $this->catalog->storeVariants($store, $variantIds);
        $notOrderable = [];
        $pieces = [];

        foreach ($lines as $line) {
            if ($line->mode === SaleMode::Wholesale && $audience !== OrderAudience::Company) {
                throw new NotForYourAccount;
            }

            $variant = $variants[$line->variantId] ?? null;
            [$least, $most] = $line->mode === SaleMode::Wholesale
                ? [$variant?->wholesaleMinimum, $variant?->wholesaleMaximum]
                : [$variant?->retailMinimum, $variant?->retailMaximum];

            if ($variant === null || ! $variant->isActive || ! $variant->orderable || $variant->notAvailableNow
                || ! in_array($line->mode, $variant->saleModes, true) || $line->quantity < ($least ?? 1) || ($most !== null && $line->quantity > $most)) {
                $notOrderable[] = $line->variantId;
            }

            $pieces[$line->variantId] = ($pieces[$line->variantId] ?? 0) + $line->quantity;
        }

        $productIds = array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, $variants)));
        $open = $this->catalog->productsOpenTo($store, $audience === OrderAudience::Company ? ShopperKind::Company : ShopperKind::Individual, $productIds);

        if (array_diff($productIds, $open) !== []) {
            throw new NotForYourAccount;
        }

        $stocks = $stock ? $this->inventory->stock($store, $variantIds) : [];

        foreach ($stock ? $variantIds : [] as $variantId) {
            $held = $stocks[$variantId] ?? null;

            if ($held === null || ! $held->orderable || ($held->available !== null && $held->available < $pieces[$variantId])) {
                $notOrderable[] = $variantId;
            }
        }

        if ($notOrderable !== []) {
            throw new NotOrderable(array_values(array_unique($notOrderable)));
        }
    }

    /**
     * One of the customer's addresses in this store, its format still accepted.
     *
     * @throws AddressNotUsable
     */
    public function address(StoreId $store, string $customerId, string $addressId): AddressDto
    {
        $address = Ulids::valid($addressId) ? $this->access->address($addressId) : null;

        if ($address === null || $address->customerId !== $customerId || $address->storeId !== $store->value || ! $address->isComplete) {
            throw new AddressNotUsable;
        }

        return $address;
    }

    /**
     * The ways to pay by account (§1.9): an individual "staff will contact you" (online from stage 7); a
     * company a bank transfer once the store has an account, or "staff will contact you".
     *
     * @return list<WayToPay>
     */
    public function waysToPay(StoreId $store, OrderAudience $audience): array
    {
        if ($audience === OrderAudience::Public) {
            return [WayToPay::StaffContact];
        }

        return $this->b2b->bankAccount($store->value) === null ? [WayToPay::StaffContact] : [WayToPay::BankTransfer, WayToPay::StaffContact];
    }

    /**
     * The highest level reached whose size is available as a gift after the order's own pieces of it.
     *
     * @param  list<CartLineDto>  $lines
     * @return array{?string, ?string} the level and its size
     */
    public function gift(StoreId $store, OrderAudience $audience, Money $goodsTotal, array $lines): array
    {
        $levels = $this->promotions->giftLevels($store, PromotionsAudience::from($audience->value), $goodsTotal);

        if ($levels === []) {
            return [null, null];
        }

        $stock = $this->inventory->stock($store, array_values(array_unique(array_map(static fn ($level): string => $level->variantId, $levels))));

        foreach ($levels as $level) {
            $own = array_sum(array_map(static fn (CartLineDto $line): int => $line->variantId === $level->variantId ? $line->quantity : 0, $lines));

            if (($stock[$level->variantId]->availableAsGift ?? 0) - $own >= 1) {
                return [$level->levelId, $level->variantId];
            }
        }

        return [null, null];
    }
}
