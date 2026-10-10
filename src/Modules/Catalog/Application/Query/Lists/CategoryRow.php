<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * A category as the categories screen reads it (catalog.md §1.5, §4.4 S2): its own products only —
 * the screen adds what sits below it — and its place in one store's menu, the base store's beside it.
 */
final readonly class CategoryRow
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $nameAr,
        public string $nameEn,
        public ?string $slugAr,
        public ?string $slugEn,
        public bool $active,
        public bool $deactivatedWithParent,
        public ?string $imageMediaId,
        public int $products,
        public ?int $storeRank,
        public ?int $baseRank,
    ) {}
}
