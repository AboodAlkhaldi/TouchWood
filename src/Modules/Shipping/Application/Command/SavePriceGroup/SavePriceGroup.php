<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\SavePriceGroup;

/**
 * A carrier's price group saved (shipping.md §1.4): a new one (no group id) or one changed - its
 * amount, and exactly these places. A place already in another group of the carrier is refused unless
 * it is among the moves ("Move here"), which take it out of there in the same save. `allUnpriced` adds
 * the country's "Select all": every city not yet priced in the carrier, nor under a priced region - or,
 * where the country has no city, every region not yet priced (owner, 2026-10-29, gap 4.2).
 */
final readonly class SavePriceGroup
{
    /**
     * @param  list<string>  $placeIds
     * @param  list<string>  $moveIds
     */
    public function __construct(
        public string $storeId,
        public string $carrierId,
        public ?string $groupId,
        public int $amountMinor,
        public array $placeIds,
        public array $moveIds = [],
        public bool $allUnpriced = false,
    ) {}
}
