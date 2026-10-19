<?php

declare(strict_types=1);

namespace Tests\Modules\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Public\Contracts\ProductStoreSection;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * A module's part of a product's Stores tab, for the tests (catalog.md amendment 23(e)): one query for
 * every card - how many of the product's sizes each store has a row for - and a line for each store but
 * the one it is told to leave out. What it was given is kept, to prove it was asked once.
 */
final class CountingStoreSection implements ProductStoreSection
{
    /** @var list<array{product: string, variants: list<string>, stores: list<string>}> */
    public static array $asked = [];

    public static ?string $leaveOut = null;

    public function component(): string
    {
        return 'Testing/StoreRows';
    }

    public function read(string $productId, array $variantIds, array $stores): array
    {
        self::$asked[] = ['product' => $productId, 'variants' => $variantIds, 'stores' => array_map(static fn (StoreDto $store): string => $store->code, $stores)];
        $rows = DB::table('catalog.store_variants')->where('product_id', $productId)->selectRaw('store_id, count(*) as rows')->groupBy('store_id')->pluck('rows', 'store_id')->all();
        $parts = [];

        foreach ($stores as $store) {
            if ($store->code !== self::$leaveOut) {
                $parts[$store->id] = ['rows' => (int) ($rows[$store->id] ?? 0), 'line' => "Rows in {$store->code}"];
            }
        }

        return $parts;
    }
}
