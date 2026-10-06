<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AcceptImportedProducts;

/**
 * Accepting an import's products (catalog.md §1.12, page part 4; amendment 6(f)): the chosen, or
 * every one ready to be.
 */
final readonly class AcceptImportedProducts
{
    /**
     * @param  array<array-key, mixed>|null  $productIds  the import's products chosen on the page, or null for every one ready
     */
    public function __construct(
        public string $importId,
        public ?array $productIds,
    ) {}
}
