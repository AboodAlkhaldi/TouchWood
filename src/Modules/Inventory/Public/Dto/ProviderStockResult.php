<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Dto;

/**
 * What `ProviderStock::apply` did with a read's numbers (inventory.md §2.5), each list in variant ids as
 * given: the sizes whose in stock changed, and those left as they are for Sync to report - archived,
 * not found, a number out of the ledger's bounds. `wired` false: the store was not wired when the
 * numbers arrived, and nothing was written.
 */
final readonly class ProviderStockResult
{
    /**
     * @param  list<string>  $changed
     * @param  list<string>  $archived
     * @param  list<string>  $notFound
     * @param  list<string>  $invalid
     */
    public function __construct(
        public bool $wired,
        public array $changed = [],
        public array $archived = [],
        public array $notFound = [],
        public array $invalid = [],
    ) {}
}
