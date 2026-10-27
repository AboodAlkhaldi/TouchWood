<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Storefront;

use Modules\Access\Public\Contracts\GuestIdentity;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Contracts\ShopProductSection;
use Modules\Catalog\Public\Dto\ShopProductVisitDto;
use Modules\Catalog\Public\Dto\StoreVariantDto;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\StockDto;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Presentation\Http\Resource\ShopCartData;
use Modules\Sales\Presentation\Http\Resource\ShopCartLimitsData;
use Modules\Sales\Presentation\Http\Resource\ShopCartSizeData;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;

/**
 * **Add to Cart on a product's page** (sales.md §4.2 B15), `sales.cart`, on an available page only, for a
 * customer or a guest (staff have no cart): for each size the page shows, in its order, each mode the size
 * is sold in now that this viewer may buy it in — an individual retail only; a company both, an unapproved
 * one too, the cart marking its wholesale lines until B2B approves it (owner, 2026-10-27); a guest both,
 * wholesale only where companies may buy the product, as no account could order it otherwise — with its
 * line's limits, what the line holds, and whether too few are free for the least that leaves the line
 * within them. The checks are the cart's own (`AddCartLine` adds to the line; `ViewCart` marks it), so
 * what is offered here is what the cart takes. The same reads whatever the number of sizes: the sizes in
 * the store, their stock, the cart's lines (none for a browser that is not a guest yet), and for a guest
 * whether companies may buy the product. A size the kept page shows but sold here no more has no mode.
 */
final readonly class CartSection implements ShopProductSection
{
    public function __construct(
        private ActorContext $actors,
        private CatalogApi $catalog,
        private InventoryApi $inventory,
        private Carts $carts,
        private GuestIdentity $guests,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function read(ShopProductVisitDto $visit): ?array
    {
        if ($visit->sizes === [] || ! in_array($this->actors->current()->type, [ActorType::Customer, ActorType::Guest], true)) {
            return null;
        }

        $ids = array_map('strtolower', $visit->sizes);
        $variants = $this->catalog->storeVariants($visit->store, $ids);
        $stock = $this->inventory->stock($visit->store, $ids);
        $guestId = $visit->customerId === null ? $this->guests->current() : null;
        $lines = $visit->customerId === null && $guestId === null ? [] : $this->carts->piecesOf($visit->store, $visit->customerId, $guestId, $ids);
        $wholesaleTo = match ($visit->viewer) {
            ShopperKind::Individual => false,
            ShopperKind::Company => true,
            ShopperKind::Guest => $this->catalog->productsOpenTo($visit->store, ShopperKind::Company, [$visit->productId]) !== [],
        };
        $sizes = [];

        foreach ($ids as $variantId) {
            $variant = $variants[$variantId] ?? null;
            $held = $stock[$variantId] ?? null;
            $inCart = $lines[$variantId] ?? [];
            $pieces = array_sum($inCart);
            $sold = $variant !== null && $variant->isActive && $variant->orderable && ! $variant->notAvailableNow;

            $sizes[] = new ShopCartSizeData(
                $variantId,
                $sold ? self::mode($variant, SaleMode::Retail, $held, $inCart, $pieces) : null,
                $sold && $wholesaleTo ? self::mode($variant, SaleMode::Wholesale, $held, $inCart, $pieces) : null,
                // Not one more piece free, the cart's own counted.
                ! self::free($held, $pieces + 1),
                $pieces,
            );
        }

        return (new ShopCartData($sizes))->toArray();
    }

    /**
     * The mode's line, when the size is sold in it: its limits, what it holds, and whether the least add
     * that leaves it within them — up to its minimum, or one piece — finds enough free.
     *
     * @param  array<string, int>  $inCart  mode => pieces
     */
    private static function mode(StoreVariantDto $variant, SaleMode $mode, ?StockDto $held, array $inCart, int $pieces): ?ShopCartLimitsData
    {
        if (! in_array($mode, $variant->saleModes, true)) {
            return null;
        }

        [$least, $most] = $mode === SaleMode::Wholesale ? [$variant->wholesaleMinimum ?? 1, $variant->wholesaleMaximum] : [$variant->retailMinimum, $variant->retailMaximum];
        $line = $inCart[$mode->value] ?? 0;

        return new ShopCartLimitsData($least, $most, $line, ! self::free($held, $pieces + max(1, $least - $line)));
    }

    /** Whether this many pieces of the size, over both modes, are free — as the cart counts stock (§1.1). */
    private static function free(?StockDto $held, int $pieces): bool
    {
        return $held !== null && $held->orderable && ($held->available === null || $held->available >= $pieces);
    }
}
