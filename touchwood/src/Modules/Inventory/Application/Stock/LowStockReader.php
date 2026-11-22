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
        [$low, , $wired, $default] = $this->figures($store, $wired);
        [$kept, $briefs] = $this->withoutArchived($low);

        return new LowRead($kept, $briefs, $wired, $default);
    }

    /**
     * **A recount's read** (amendment 1): the low sizes, as `low()` gives them, and of the sizes the last
     * recount kept that are not low now, those **no longer on the list for another reason** - archived (or
     * of an archived product), neither switched on nor stocked, unknown to Catalog - which leave the kept set
     * silently (§1.7). Still one read of Catalog's briefs, over the low sizes and those leaving.
     *
     * @param  list<string>  $previous  the sizes the store's kept set holds
     */
    public function recount(StoreId $store, array $previous): LowRecount
    {
        [$low, $listed] = $this->figures($store, null);
        $lowIds = array_flip(array_map(static fn (LowSize $size): string => $size->variantId, $low));
        $previous = array_values(array_unique($previous));
        // The kept sizes not low by their figures, still the store's: asked in the same read as the low ones.
        $others = array_values(array_filter($previous, static fn (string $id): bool => ! isset($lowIds[$id]) && isset($listed[$id])));
        [$kept, $briefs] = $this->withoutArchived($low, $others);
        $keptIds = array_flip(array_map(static fn (LowSize $size): string => $size->variantId, $kept));

        // Leaving the list: low by its figures but archived, or no longer the store's, or unknown - silently.
        $gone = array_values(array_filter($previous, static fn (string $id): bool => ! isset($keptIds[$id])
            && (! isset($listed[$id]) || ! self::onSale($briefs[$id] ?? null))));

        return new LowRecount($kept, $gone);
    }

    /**
     * Every size on the store's list - switched on there, or with a stock row - and those at or below their
     * threshold, the fewest left first, then by size id. The store's rows in one query, every size switched
     * on in another.
     *
     * @return array{list<LowSize>, array<string, true>, bool, int} the low sizes, every size listed (by id),
     *                                                              the wiring and the default threshold
     */
    private function figures(StoreId $store, ?bool $wired): array
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

        return [$low, array_fill_keys($variantIds, true), $wired, $default];
    }

    /**
     * An archived size - or a size of an archived product - is left off: staff cannot change its stock
     * until it is restored, and then it is back if it is low (owner, 2026-10-13). A size Catalog no
     * longer has is left off too. One read of Catalog, over the low sizes only - the sizes in brief
     * (amendment 23(h)), which also name them for the list's order - and any others the caller names.
     *
     * @param  list<LowSize>  $low
     * @param  list<string>  $alsoBrief
     * @return array{list<LowSize>, array<string, VariantBriefDto>} the sizes kept, and each in brief
     */
    private function withoutArchived(array $low, array $alsoBrief = []): array
    {
        if ($low === [] && $alsoBrief === []) {
            return [[], []];
        }

        $briefs = $this->catalog->variantBriefs([...array_map(static fn (LowSize $size): string => $size->variantId, $low), ...$alsoBrief]);

        return [array_values(array_filter($low, static fn (LowSize $size): bool => self::onSale($briefs[$size->variantId] ?? null))), $briefs];
    }

    /** Known to Catalog, and neither the size nor its product archived. */
    private static function onSale(?VariantBriefDto $brief): bool
    {
        return $brief !== null && ! $brief->isArchived && $brief->productStage !== ProductStage::Archived;
    }
}
