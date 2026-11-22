<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use Modules\Content\Domain\Model\BlogCategory;

/** The blog's categories (content.md §1.12, §5: `content.blog_categories`). */
interface BlogCategories
{
    public function find(string $id): ?BlogCategory;

    public function lock(string $id): ?BlogCategory;

    /**
     * The store's categories in order, locked when asked.
     *
     * @return list<BlogCategory>
     */
    public function ofStore(string $storeId, bool $lock = false): array;

    public function add(BlogCategory $category, ?string $by): void;

    public function update(BlogCategory $category, ?string $by): void;

    public function delete(string $id): void;

    /**
     * Each category's place, as listed.
     *
     * @param  list<string>  $ids
     */
    public function reorder(array $ids): void;
}
