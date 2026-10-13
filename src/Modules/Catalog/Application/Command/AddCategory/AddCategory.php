<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddCategory;

/**
 * A new category (catalog.md §1.5): under a parent, or at the top, **last among its siblings in every
 * store** — no place is typed (amendment 16(d), replacing 1(d)). Slugs left empty are made from the
 * names.
 */
final readonly class AddCategory
{
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public ?string $parentId = null,
        public ?string $slugAr = null,
        public ?string $slugEn = null,
        public ?string $imageMediaId = null,
    ) {}
}
