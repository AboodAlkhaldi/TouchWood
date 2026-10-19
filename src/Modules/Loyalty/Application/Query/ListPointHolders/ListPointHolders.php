<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\ListPointHolders;

/**
 * The store's points holders (loyalty.md §4.1 L1): most live points first, 50 at a time, or those
 * whose customer matches a search.
 */
final readonly class ListPointHolders
{
    /**
     * @param  int|null  $afterPoints  the last row's live points, with its account id, for the next page
     */
    public function __construct(
        public string $storeId,
        public ?string $search = null,
        public ?int $afterPoints = null,
        public ?string $afterAccountId = null,
        public int $perPage = 50,
    ) {}
}
