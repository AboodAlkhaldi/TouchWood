<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\MoveCategory;

/**
 * A category moved under another, or to the top, with its place among its new siblings — written
 * into every store's order, as when it was added (catalog.md §1.5, amendment 1(d)).
 */
final readonly class MoveCategory
{
    public function __construct(
        public string $categoryId,
        public ?string $parentId,
        public int $rank = 0,
    ) {}
}
