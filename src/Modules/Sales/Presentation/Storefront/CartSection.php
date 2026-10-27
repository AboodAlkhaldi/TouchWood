<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Storefront;

use Modules\Access\Public\Contracts\GuestIdentity;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Contracts\ShopProductSection;
use Modules\Catalog\Public\Dto\ShopProductVisitDto;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Presentation\Http\Resource\ShopCartData;
use Modules\Sales\Presentation\Http\Resource\ShopCartLimitsData;
use Modules\Sales\Presentation\Http\Resource\ShopCartSizeData;

/**
 * **Add to Cart on a product's page** (sales.md §4.2 B15), `sales.cart`, on an available page only: for
 * each size the page shows, in its order, what this viewer may add — the limits of each mode the size is
 * sold in now and they may buy it in (an individual retail only; a guest and a company both, an
 * unapproved company too, the cart marking its wholesale lines until B2B approves it — owner, 2026-10-27),
 * whether too few are free for even the least a line may hold, and the pieces of it already in their cart.
 * The checks are the cart's own (`AddCartLine`, `ViewCart`), so what is offered here is what the cart
 * takes. The same reads whatever the number of sizes: the sizes in the store, their stock, the cart's
 * pieces (none for a browser that is not a guest yet).
 */
final readonly class CartSection implements ShopProductSection
{
    public function __construct(
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
        if ($visit->sizes === []) {
            return null;
        }

        $variants = $this->catalog->storeVariants($visit->store, $visit->sizes);
        $stock = $this->inventory->stock($visit->store, $visit->sizes);
        $guestId = $visit->customerId === null ? $this->guests->current() : null;
        $inCart = $visit->customerId === null && $guestId === null ? [] : $this->carts->piecesOf($visit->store, $visit->customerId, $guestId, $visit->sizes);
        $sizes = [];

        foreach ($visit->sizes as $variantId) {
            $variant = $variants[strtolower($variantId)] ?? null;
            $sold = $variant !== null && $variant->isActive && $variant->orderable && ! $variant->notAvailableNow;
            $retail = $sold && in_array(SaleMode::Retail, $variant->saleModes, true)
                ? new ShopCartLimitsData($variant->retailMinimum, $variant->retailMaximum) : null;
            $wholesale = $sold && $visit->viewer !== ShopperKind::Individual && in_array(SaleMode::Wholesale, $variant->saleModes, true)
                ? new ShopCartLimitsData($variant->wholesaleMinimum ?? 1, $variant->wholesaleMaximum) : null;
            $least = min($retail->minimum ?? PHP_INT_MAX, $wholesale->minimum ?? PHP_INT_MAX);
            $held = $stock[$variantId] ?? null;
            $pieces = $inCart[$variantId] ?? 0;

            $sizes[] = new ShopCartSizeData(
                $variantId,
                $retail,
                $wholesale,
                // Counted with the pieces already in the cart, as the cart counts them (§1.1).
                $held === null || ! $held->orderable || ($held->available !== null && $least !== PHP_INT_MAX && $held->available < $pieces + $least),
                $pieces,
            );
        }

        return (new ShopCartData($sizes))->toArray();
    }
}
