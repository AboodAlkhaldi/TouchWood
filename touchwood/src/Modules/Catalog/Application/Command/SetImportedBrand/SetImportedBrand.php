<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedBrand;

/**
 * One brand for the products of an import, before they are brought in (catalog.md §1.12, amendment 7(c)).
 */
final readonly class SetImportedBrand
{
    /**
     * @param  array<array-key, mixed>|null  $productIds  the import's products chosen on the page, or null for all
     * @param  string  $mode  REPLACE, or FILL_EMPTY: only the products the file gave no brand
     */
    public function __construct(
        public string $importId,
        public ?array $productIds,
        public string $brandId,
        public string $mode,
    ) {}
}
