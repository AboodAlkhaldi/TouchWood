<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedStores;

/**
 * The stores the products of an import are switched on in when accepted (catalog.md §1.12, amendments
 * 7(c), 8(b)).
 */
final readonly class SetImportedStores
{
    /**
     * @param  array<array-key, mixed>|null  $productIds  the import's products chosen on the page, or null for all
     * @param  array<array-key, mixed>  $storeCodes  as the panel shows them (sa, eg)
     * @param  string  $mode  REPLACE — the stores become exactly these —, or FILL_EMPTY: only the products with none
     */
    public function __construct(
        public string $importId,
        public ?array $productIds,
        public array $storeCodes,
        public string $mode,
    ) {}
}
