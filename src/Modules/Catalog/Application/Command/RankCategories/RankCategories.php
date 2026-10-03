<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RankCategories;

/**
 * One store's order of its menu (catalog.md §1.5, §3): each category's place among its siblings
 * there. Only the categories sent change.
 */
final readonly class RankCategories
{
    /**
     * @param  array<string, int>  $ranks  category id → its place
     */
    public function __construct(
        public string $storeId,
        public array $ranks,
    ) {}
}
