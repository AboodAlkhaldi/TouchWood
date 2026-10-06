<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedFilters;

/**
 * Filter values for the products of an import, before they are brought in (catalog.md §1.12, amendment 7(c)).
 */
final readonly class SetImportedFilters
{
    /**
     * @param  array<array-key, mixed>|null  $productIds  the import's products chosen on the page, or null for all
     * @param  array<array-key, mixed>  $valueIds  values of filter attributes
     * @param  string  $mode  ADD to each one's filters, REPLACE them, or FILL_EMPTY: only the products that have none
     */
    public function __construct(
        public string $importId,
        public ?array $productIds,
        public array $valueIds,
        public string $mode,
    ) {}
}
