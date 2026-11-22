<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\ListPointHolders;

/**
 * A page of the store's points holders. `tooMany`: the search matched so many customers that a
 * list would look complete without being so (loyalty.md §9.5 P2) - none are listed.
 */
final readonly class PointHoldersPage
{
    /**
     * @param  list<PointHolder>  $rows
     */
    public function __construct(
        public array $rows,
        public ?int $nextPoints,
        public ?string $nextAccountId,
        public bool $tooMany = false,
    ) {}
}
