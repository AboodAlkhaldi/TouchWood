<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Inventory\Application\InventorySettings;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Repository\StoreProducts;
use Modules\Inventory\Domain\Service\StockFigures;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * Tells Catalog whether each size can be ordered and is "ending soon" there (inventory.md §1.2, §1.3,
 * §2.3), inside the change's own transaction - only what flipped since Catalog was last told, kept on
 * the size's row. With `all` (the repair, a size just switched on) every size is told, a size with no
 * row too: it counts as 0.
 */
final readonly class StockFacts
{
    public function __construct(
        private PlatformApi $platform,
        private StoreWiring $wiring,
        private StockRows $stock,
        private StoreProducts $products,
        private ListingFacts $listing,
    ) {}

    /**
     * @param  list<string>  $variantIds
     * @return int the sizes Catalog was told of
     */
    public function refresh(StoreId $store, array $variantIds, bool $all = false): int
    {
        $variantIds = array_values(array_unique($variantIds));

        if ($variantIds === []) {
            return 0;
        }

        $wired = $this->wiring->isWired($store);
        $rows = $this->stock->read($store, $variantIds);
        $default = (int) $this->platform->setting(InventorySettings::LOW_STOCK_DEFAULT, $store)->value;
        $switchedOn = $wired ? [] : array_flip($this->products->endingSoonOf($store, array_values(array_unique(array_map(static fn ($row): string => $row->productId, $rows)))));
        $orderable = $notOrderable = $endingSoon = $notEndingSoon = $told = [];

        foreach ($variantIds as $variantId) {
            $row = $rows[$variantId] ?? null;

            // A size with no row was never told, and is told only when every size is.
            if ($row === null && ! $all) {
                continue;
            }

            $canOrder = StockFigures::orderable($row, $wired);
            $lastPieces = StockFigures::endingSoon($row, $wired, $default, $row !== null && isset($switchedOn[$row->productId]));

            if ($all || $row->orderablePushed !== $canOrder) {
                $canOrder ? $orderable[] = $variantId : $notOrderable[] = $variantId;
                $told[$variantId] = true;
            }

            if ($all || $row->endingSoonPushed !== $lastPieces) {
                $lastPieces ? $endingSoon[] = $variantId : $notEndingSoon[] = $variantId;
                $told[$variantId] = true;
            }

            if ($row !== null && isset($told[$variantId])) {
                $this->stock->markPushed($store, $variantId, $canOrder, $lastPieces);
            }
        }

        foreach ([[$orderable, true], [$notOrderable, false]] as [$ids, $value]) {
            if ($ids !== []) {
                $this->listing->orderable($store, $ids, $value);
            }
        }

        foreach ([[$endingSoon, true], [$notEndingSoon, false]] as [$ids, $value]) {
            if ($ids !== []) {
                $this->listing->endingSoon($store, $ids, $value);
            }
        }

        return count($told);
    }
}
