<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListSales;

/**
 * A page of sales: its rows, the cursor of the next page (none on the last), and how many the filters find.
 */
final readonly class SaleList
{
    /**
     * @param  list<SaleRow>  $sales
     */
    public function __construct(
        public array $sales,
        public ?string $next,
        public int $total,
    ) {}
}
