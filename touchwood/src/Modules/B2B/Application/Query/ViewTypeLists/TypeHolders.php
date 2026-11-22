<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewTypeLists;

/**
 * How many companies hold each company type of one store — one query for the whole list, not one
 * per type (b2b.md §4.6).
 */
interface TypeHolders
{
    /**
     * @return array<string, int> company type id => companies holding it; a type nobody holds is absent
     */
    public function countsFor(string $storeId): array;
}
