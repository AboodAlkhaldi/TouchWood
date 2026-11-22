<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Inventory\Application\InventorySettings;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Service\StockFigures;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Inventory\Public\Dto\StockLevelDto;
use Modules\Inventory\Public\Dto\StockLevelsDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Each size's stock in a store, for Ops' Stock report** (`InventoryApi::stockLevels`, amendment 1): the
 * sizes the Stock screen lists - switched on there, or with a stock row - in size-id order, keyset-paged.
 * Low and out as the Low Stock list decides them (§1.7): an archived size is neither. The store's rows in
 * one query, the sizes switched on in another, the page's sizes in brief in a third - whatever the page;
 * each page reads the store afresh.
 */
final readonly class StockLevels
{
    /** The most sizes a page holds. */
    public const int MAX = 1000;

    public function __construct(
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private StoreWiring $wiring,
        private StockRows $stock,
    ) {}

    public function page(StoreId $store, ?string $after, int $limit): StockLevelsDto
    {
        $limit = min(max($limit, 1), self::MAX);
        $rows = $this->stock->ofStore($store);
        $ids = array_values(array_unique([
            ...$this->catalog->switchedOnVariantIds($store),
            ...array_map('strval', array_keys($rows)),
        ]));
        sort($ids, SORT_STRING);

        if ($after !== null) {
            $after = strtolower(trim($after));
            $ids = array_values(array_filter($ids, static fn (string $id): bool => strcmp($id, $after) > 0));
        }

        $page = array_slice($ids, 0, $limit);

        if ($page === []) {
            return new StockLevelsDto([], null);
        }

        $wired = $this->wiring->isWired($store);
        $default = (int) $this->platform->setting(InventorySettings::LOW_STOCK_DEFAULT, $store)->value;
        $briefs = $this->catalog->variantBriefs($page);
        $levels = [];

        foreach ($page as $id) {
            $row = $rows[$id] ?? null;
            $brief = $briefs[$id] ?? null;
            // Unknown to Catalog is as good as archived: not on sale, never on the Low Stock list.
            $archived = $brief === null || $brief->isArchived || $brief->productStage === ProductStage::Archived;
            $free = StockFigures::free($row, $wired);
            $threshold = $row->threshold ?? $default;
            $listed = ! $archived && $free <= $threshold;

            $levels[] = new StockLevelDto(
                $id,
                $row->inStock ?? 0,
                $wired ? ($row->returnedExtra ?? 0) : 0,
                $row->held ?? 0,
                $free,
                $threshold,
                StockFigures::counts($row, $wired),
                $archived,
                $listed && $free > 0,
                $listed && $free === 0,
            );
        }

        return new StockLevelsDto($levels, count($ids) > $limit ? $page[array_key_last($page)] : null);
    }
}
