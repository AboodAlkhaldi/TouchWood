<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Home;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\LowSize;
use Modules\Inventory\Application\Stock\LowStockReader;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeRow;
use Modules\Platform\Public\Dto\HomeScope;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * Inventory's card on the admin home, Low Stock (inventory.md §1.7; the handoff's dashboard "low
 * stock"): how many sizes are low, and the fewest left first - counted by the reader the list uses, so
 * the two never disagree. Offered to holders of **Manage Stock** in the scope; it asks again itself,
 * as the list does, because it reads across stores (handoff §19). Its link comes with the list's screen.
 */
final readonly class LowStockCard implements HomeCard
{
    /** How many of the low sizes are named. */
    public const int NAMED = 5;

    public function __construct(
        private LowStockReader $reader,
        private CatalogApi $catalog,
        private PlatformApi $platform,
        private Authorizer $authorizer,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $held = $this->authorizer->storesWith(InventoryPermissions::STOCK_MANAGE);
        // All Stores: every store, for a reader holding Manage Stock in every store, now and later.
        $stores = $scope->stores() ?? ($held === null ? array_map(static fn ($store): StoreId => $store->storeId(), $this->platform->allStores()) : null);

        if ($stores === null || ! self::mayRead($stores, $held)) {
            return null;
        }

        $stores = $this->reader->shown($stores);

        if ($stores === []) {
            return null;
        }

        /** @var list<array{StoreId, LowSize}> $low */
        $low = [];

        foreach ($stores as $store) {
            foreach ($this->reader->low($store) as $size) {
                $low[] = [$store, $size];
            }
        }

        usort($low, static fn (array $a, array $b): int => [$a[1]->free, $a[1]->variantId] <=> [$b[1]->free, $b[1]->variantId]);
        $named = array_slice($low, 0, self::NAMED);

        return new HomeCardData(
            [new HomeFigure('low', count($low), tone: $low === [] ? null : 'amber')],
            $this->rows($named, count($stores) > 1),
            $named === [] ? null : 'fewest',
        );
    }

    /**
     * @param  list<array{StoreId, LowSize}>  $named
     * @return list<HomeRow>
     */
    private function rows(array $named, bool $withStore): array
    {
        $variants = $this->catalog->variants(array_map(static fn (array $low): string => $low[1]->variantId, $named));
        $products = $this->catalog->products(array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, array_values($variants)))));
        $english = app()->getLocale() === 'en';
        $rows = [];

        foreach ($named as [$store, $size]) {
            $variant = $variants[$size->variantId] ?? null;
            $product = $variant === null ? null : ($products[$variant->productId] ?? null);

            if ($variant === null || $product === null) {
                continue;
            }

            $left = trans('inventory::home.low_stock.left', ['count' => $size->free]);
            $where = $withStore ? $this->platform->store($store)?->name->in($english ? 'en' : 'ar') : null;
            $rows[] = new HomeRow(
                ($english ? ($product->nameEn ?? $product->nameAr) : $product->nameAr).' · '.$variant->code,
                $where === null ? $left : $where.' · '.$left,
            );
        }

        return $rows;
    }

    /**
     * @param  list<StoreId>  $stores
     * @param  list<StoreId>|null  $held
     */
    private static function mayRead(array $stores, ?array $held): bool
    {
        return $held === null || array_diff(
            array_map(static fn (StoreId $store): string => $store->value, $stores),
            array_map(static fn (StoreId $store): string => $store->value, $held),
        ) === [];
    }
}
