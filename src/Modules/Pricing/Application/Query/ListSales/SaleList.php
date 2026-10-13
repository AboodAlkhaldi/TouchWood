<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListSales;

/**
 * A page of sales, and how many the store has.
 */
final readonly class SaleList
{
    /**
     * @param  list<SaleRow>  $sales
     */
    public function __construct(
        public array $sales,
        public int $total,
    ) {}
}
