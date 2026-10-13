<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\MoveCategory;

/**
 * A category moved under another, or to the top: **last among its new siblings in every store**, as
 * when it was added — no place is typed (catalog.md §1.5, amendment 16(d), replacing 2(c)).
 */
final readonly class MoveCategory
{
    public function __construct(
        public string $categoryId,
        public ?string $parentId,
    ) {}
}
