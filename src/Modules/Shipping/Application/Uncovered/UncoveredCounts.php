<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Uncovered;

/**
 * Each store's kept "Places no carrier serves" count (shipping.md §1.6), so the menu reads one row a
 * store rather than every place of every country.
 */
interface UncoveredCounts
{
    /**
     * @param  list<string>  $storeIds
     * @return array<string, int> store id => count, for the stores counted already
     */
    public function of(array $storeIds): array;

    public function write(string $storeId, int $count): void;
}
