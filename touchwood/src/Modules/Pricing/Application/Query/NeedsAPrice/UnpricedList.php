<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\NeedsAPrice;

use Modules\Pricing\Application\Query\PriceViews\SizeView;

/**
 * A page of Needs a Price (pricing.md §4.3.3 P5): the sizes - photo, product, size, code - in Catalog's
 * order, the cursor of the next page (none on the last), and how many need a price in all.
 */
final readonly class UnpricedList
{
    /**
     * @param  list<SizeView>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
