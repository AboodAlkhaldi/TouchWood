<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\FindWholesaleSizes;

use Modules\Pricing\Application\Query\PriceViews\SizeView;

/**
 * A page of sizes found for a picker: the sizes, the cursor of the next page (none on the last), and how
 * many are found in all.
 */
final readonly class FoundSizes
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
