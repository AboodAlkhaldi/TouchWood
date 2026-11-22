<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveBlogCategory;

/**
 * W8's category dialog (content.md §1.12): a new category (`categoryId` null, in `storeId`) or one that exists, its
 * name and address per language; an address left empty is made from the name.
 */
final readonly class SaveBlogCategory
{
    public function __construct(
        public ?string $categoryId,
        public ?string $storeId,
        public ?string $nameAr,
        public ?string $nameEn,
        public ?string $slugAr = null,
        public ?string $slugEn = null,
    ) {}
}
