<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteBlogCategory;

/** Deleting a blog category (content.md §1.12, W8): only an empty one - "Move its posts first." */
final readonly class DeleteBlogCategory
{
    public function __construct(
        public string $categoryId,
    ) {}
}
