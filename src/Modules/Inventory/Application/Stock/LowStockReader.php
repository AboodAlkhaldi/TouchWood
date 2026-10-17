<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Inventory\Application\InventorySettings;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Service\StockFigures;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Low stock** (inventory.md §1.7): a size is low when what can be ordered or given now is at or below
 * its threshold - its own, or the store's default. Over every size the store has switched on and every
 * size with a stock row there (a size given only as a gift, once stocked); a size with no row counts as
 * 0 (§9.2 #6); an archived size left off (owner, 2026-10-13). In a wired store an ordinary product is low by the provider's number. One reader for the
 * list, its count and the Home card, so the three never disagree.
 */
final readonly class LowStockReader
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private StoreWiring $wiring,
        private StockRows $stock,
    ) {}

    /**
     * Of these stores, the ones the reader is shown (platform.md §9.10): a store that is off only to
     * whoever may switch stores - the list refuses it to anyone else, and the count and the card agree.
     *
     * @param  list<StoreId>  $stores
     * @return list<StoreId>
     */
    public function shown(array $stores): array
    {
        if ($this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== []) {
            return $stores;
        }

        return array_values(array_filter($stores, fn (StoreId $store): bool => $this->platform->store($store)?->isActive === true));
    }

    /**
     * @return list<LowSize> the low sizes, the fewest left first, then by size id
     */
    public function low(StoreId $store): array
    {
        $variantIds = array_values(array_unique([
            ...$this->catalog->switchedOnVariantIds($store),
            ...$this->stock->variantIds($store),
        ]));
        $wired = $this->wiring->isWired($store);
        $default = (int) $this->platform->setting(InventorySettings::LOW_STOCK_DEFAULT, $store)->value;
        $rows = $this->stock->read($store, $variantIds);
        $low = [];

        foreach ($variantIds as $variantId) {
            $row = $rows[$variantId] ?? null;
            $free = StockFigures::free($row, $wired);
            $threshold = $row->threshold ?? $default;

            if ($free <= $threshold) {
                $low[] = new LowSize($variantId, $row?->productId, $row->inStock ?? 0, $row->held ?? 0, $free, $threshold);
            }
        }

        usort($low, static fn (LowSize $a, LowSize $b): int => [$a->free, $a->variantId] <=> [$b->free, $b->variantId]);

        return $this->withoutArchived($low);
    }

    /**
     * An archived size - or a size of an archived product - is left off: staff cannot change its stock
     * until it is restored, and then it is back if it is low (owner, 2026-10-13). A size Catalog no
     * longer has is left off too. Two reads of Catalog, over the low sizes only.
     *
     * @param  list<LowSize>  $low
     * @return list<LowSize>
     */
    private function withoutArchived(array $low): array
    {
        if ($low === []) {
            return [];
        }

        $variants = $this->catalog->variants(array_map(static fn (LowSize $size): string => $size->variantId, $low));
        $products = $this->catalog->products(array_values(array_unique(array_map(static fn (VariantDto $variant): string => $variant->productId, array_values($variants)))));

        return array_values(array_filter($low, static function (LowSize $size) use ($variants, $products): bool {
            $variant = $variants[$size->variantId] ?? null;
            $product = $variant === null ? null : ($products[$variant->productId] ?? null);

            return $variant !== null && ! $variant->isArchived && $product !== null && $product->stage !== ProductStage::Archived;
        }));
    }
}
