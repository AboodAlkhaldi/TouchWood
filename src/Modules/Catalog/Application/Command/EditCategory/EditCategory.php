<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditCategory;

/**
 * A category's names, slugs and photo (catalog.md §1.5), sent whole. Where it sits is MoveCategory.
 */
final readonly class EditCategory
{
    public function __construct(
        public string $categoryId,
        public string $nameAr,
        public string $nameEn,
        public ?string $slugAr = null,
        public ?string $slugEn = null,
        public ?string $imageMediaId = null,
    ) {}
}
