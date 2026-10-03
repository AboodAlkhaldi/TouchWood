<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddCategory;

/**
 * A new category (catalog.md §1.5): under a parent, or at the top; its place among its siblings,
 * starting the same in every store (amendment 1(d)). Slugs left empty are made from the names.
 */
final readonly class AddCategory
{
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public ?string $parentId = null,
        public int $rank = 0,
        public ?string $slugAr = null,
        public ?string $slugEn = null,
        public ?string $imageMediaId = null,
    ) {}
}
