<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListPriceGroups;

use Modules\Shipping\Application\Query\PlaceRow;

/**
 * One price group: its amount, in the currency's smallest unit, and its places by name in the page's
 * language.
 */
final readonly class PriceGroupRow
{
    /**
     * @param  list<PlaceRow>  $places
     */
    public function __construct(
        public string $id,
        public int $amountMinor,
        public array $places,
    ) {}
}
