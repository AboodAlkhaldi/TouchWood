<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewSale;

use Modules\Pricing\Application\Query\ListSales\SaleRow;

/**
 * A sale as its read-only dialog shows it (pricing.md §4.3.3 P2): the row the list shows, its store, and
 * each of its sizes.
 */
final readonly class SaleView
{
    /**
     * @param  list<SaleSizeView>  $sizes
     */
    public function __construct(
        public string $storeId,
        public SaleRow $sale,
        public array $sizes,
    ) {}
}
