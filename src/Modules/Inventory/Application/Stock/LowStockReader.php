<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\VariantBriefDto;
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
        return $this->read($store)->sizes;
    }

    /**
     * The low sizes of each of these stores, keyed by store id, asking whether they are wired **once for
     * all of them** (StoreWiring::wiredStores) rather than once per store - the menu's live count of the
     * stores not counted yet (owner, 2026-10-20). Asked now, in the caller's request and transaction, and
     * never kept: wiring is read each time (sync.md §1.8).
     *
     * @param  list<StoreId>  $stores
     * @return array<string, list<LowSize>>
     */
    public function lowOf(array $stores): array
    {
        if ($stores === []) {
            return [];
        }

        $wired = array_fill_keys(array_map('strtolower', $this->wiring->wiredStores()), true);
        $low = [];

        foreach ($stores as $store) {
            $low[$store->value] = $this->read($store, isset($wired[strtolower($store->value)]))->sizes;
        }

        return $low;
    }

    /**
     * The low sizes with what decided them and their products (the Low Stock list's names and order,
     * stage 5's screens step 4). The store's rows in one query, every size switched on in another.
     *
     * @param  bool|null  $wired  whether the store is wired, when the caller has just asked it of many
     *                            stores at once (lowOf); null asks it here
     */
    public function read(StoreId $store, ?bool $wired = null): LowRead
    {
        $rows = $this->stock->ofStore($store);
        $variantIds = array_values(array_unique([
            ...$this->catalog->switchedOnVariantIds($store),
            ...array_map('strval', array_keys($rows)),
        ]));
        $wired ??= $this->wiring->isWired($store);
        $default = (int) $this->platform->setting(InventorySettings::LOW_STOCK_DEFAULT, $store)->value;
        $low = [];

        foreach ($variantIds as $variantId) {
            $row = $rows[$variantId] ?? null;
            $free = StockFigures::free($row, $wired);
            $threshold = $row->threshold ?? $default;

            if ($free <= $threshold) {
                $low[] = new LowSize($variantId, $row?->productId, $row->inStock ?? 0, $row->held ?? 0, $free, $threshold, $row);
            }
        }

        usort($low, static fn (LowSize $a, LowSize $b): int => [$a->free, $a->variantId] <=> [$b->free, $b->variantId]);
        [$kept, $briefs] = $this->withoutArchived($low);

        return new LowRead($kept, $briefs, $wired, $default);
    }

    /**
     * An archived size - or a size of an archived product - is left off: staff cannot change its stock
     * until it is restored, and then it is back if it is low (owner, 2026-10-13). A size Catalog no
     * longer has is left off too. One read of Catalog, over the low sizes only - the sizes in brief
     * (amendment 23(h)), which also name them for the list's order.
     *
     * @param  list<LowSize>  $low
     * @return array{list<LowSize>, array<string, VariantBriefDto>} the sizes kept, and each in brief
     */
    private function withoutArchived(array $low): array
    {
        if ($low === []) {
            return [[], []];
        }

        $briefs = $this->catalog->variantBriefs(array_map(static fn (LowSize $size): string => $size->variantId, $low));

        return [array_values(array_filter($low, static function (LowSize $size) use ($briefs): bool {
            $brief = $briefs[$size->variantId] ?? null;

            return $brief !== null && ! $brief->isArchived && $brief->productStage !== ProductStage::Archived;
        })), $briefs];
    }
}
