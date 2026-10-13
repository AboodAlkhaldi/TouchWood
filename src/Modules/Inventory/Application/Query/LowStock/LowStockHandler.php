<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\LowStock;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\LowSize;
use Modules\Inventory\Application\Stock\LowStockReader;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\StoreOff;
use Shared\Application\Unauthorized;

/**
 * **The Low Stock list** (inventory.md §1.7, §3) of one store, under **Manage Stock** there: every low
 * size, the fewest left first, named in two reads of Catalog however many there are (§2.4) - the
 * sizes, then their products. A read: no lock, nothing written.
 */
final readonly class LowStockHandler
{
    public const string PERMISSION = InventoryPermissions::STOCK_MANAGE;

    public function __construct(
        private InventoryChange $change,
        private LowStockReader $reader,
        private CatalogApi $catalog,
    ) {}

    /**
     * @return list<LowStockRow>
     *
     * @throws Unauthorized|InvalidInventoryInput|StoreOff
     */
    public function handle(LowStock $query): array
    {
        $store = $this->change->authorize(self::PERMISSION, $query->storeId)->storeId();
        $low = $this->reader->low($store);
        $variants = $this->catalog->variants(array_map(static fn (LowSize $size): string => $size->variantId, $low));
        $products = $this->catalog->products(array_values(array_unique(array_map(
            static fn (LowSize $size): string => $size->productId ?? $variants[$size->variantId]->productId ?? '',
            $low,
        ))));
        $rows = [];

        foreach ($low as $size) {
            $variant = $variants[$size->variantId] ?? null;
            $product = $variant === null ? null : ($products[$variant->productId] ?? null);

            // A size Catalog no longer has (a draft's, deleted) has nothing to show.
            if ($variant === null || $product === null) {
                continue;
            }

            $rows[] = new LowStockRow($size->variantId, $product->id, $product->nameAr, $product->nameEn, $variant->code, $size->inStock, $size->held, $size->free, $size->threshold);
        }

        return $rows;
    }
}
