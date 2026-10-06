<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedSlugs;

/**
 * A product's own web address, given on the import's page when the one it would have is taken
 * (catalog.md §1.12, amendment 8(c)).
 */
final readonly class SetImportedSlugs
{
    public function __construct(
        public string $importId,
        public string $productId,
        public ?string $slugAr,
        public ?string $slugEn,
    ) {}
}
