<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListPrices;

/**
 * A page of the Prices screen: its rows, the cursor of the next page (none on the last) and how many
 * rows the filters find in all ("Showing 50 of 312").
 */
final readonly class PriceList
{
    /**
     * @param  list<PriceRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
