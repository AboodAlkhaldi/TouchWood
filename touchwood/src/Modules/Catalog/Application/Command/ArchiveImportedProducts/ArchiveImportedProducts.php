<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ArchiveImportedProducts;

/**
 * Archiving products an import created and nobody wants (catalog.md §1.12, page part 4; amendment 6(e)).
 */
final readonly class ArchiveImportedProducts
{
    /**
     * @param  array<array-key, mixed>|null  $productIds  the import's products chosen on the page, or null for every one it created and nobody accepted
     */
    public function __construct(
        public string $importId,
        public ?array $productIds,
    ) {}
}
