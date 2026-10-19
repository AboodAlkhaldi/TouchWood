<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\AddSizeChoices;

/**
 * **Add Size's picker** (inventory.md §4.3.3 I1, Q24): the sizes a store may start stocking - one it does
 * not sell, given only as a gift (§1.7) - found by a name in either language or a code, 50 at a time.
 */
final readonly class AddSizeChoices
{
    public const int PER_PAGE = 50;

    /** How many already-listed sizes a search names, each "Already listed", opening its row. */
    public const int LISTED_NAMED = 5;

    public function __construct(
        public string $storeId,
        public ?string $search = null,
        public ?string $after = null,
    ) {}
}
