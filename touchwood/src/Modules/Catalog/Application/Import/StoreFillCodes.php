<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * **A store file's codes, read together** (catalog.md §4.4 S10, P13): a file holds up to 1,000, and
 * its page stays within the panel's 15 queries (frontend.md §5).
 */
interface StoreFillCodes
{
    /**
     * @param  list<string>  $codes
     * @return array<string, StoreFillCode> by code; a code no product holds is left out
     */
    public function in(string $storeId, array $codes): array;
}
