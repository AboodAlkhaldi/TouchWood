<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListWholesale;

/**
 * A page of the Wholesale Prices screen: its rows, the cursor of the next page (none on the last), and
 * how many sizes the search finds.
 */
final readonly class WholesaleList
{
    /**
     * @param  list<WholesaleRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
