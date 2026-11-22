<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\OrderBlogCategories;

/** The blog's categories dragged into a new order (content.md §1.12, W8): every one of the store's, each once. */
final readonly class OrderBlogCategories
{
    /**
     * @param  list<string>  $categoryIds  first to last
     */
    public function __construct(
        public ?string $storeId,
        public array $categoryIds,
    ) {}
}
