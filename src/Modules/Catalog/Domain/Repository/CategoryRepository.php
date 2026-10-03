<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Model\Category;

interface CategoryRepository
{
    public function nextId(): string;

    public function find(string $categoryId): ?Category;

    /** Read again, its row locked, inside a change. */
    public function byId(string $categoryId): ?Category;

    /**
     * @return list<Category> the categories directly under this one — every top category for null
     */
    public function childrenOf(?string $categoryId): array;

    /**
     * @return list<string> every category under this one, at any depth
     */
    public function idsBelow(string $categoryId): array;

    public function slugTaken(string $locale, string $slug, ?string $exceptCategoryId = null): bool;

    /** Inserts the category and records its two slugs as current. */
    public function add(Category $category): void;

    /** Writes the category; a slug that changed joins its history and the old one stays held. */
    public function update(Category $category): void;

    public function delete(string $categoryId): void;

    /**
     * @return list<Category>
     */
    public function all(): array;

    /**
     * @return list<string> the categories whose photo this media is
     */
    public function withImage(string $mediaId): array;

    /**
     * Its place among its siblings in each of these stores (catalog.md §1.5, amendment 1(d)).
     *
     * @param  list<string>  $storeIds
     */
    public function placeIn(string $categoryId, array $storeIds, int $rank): void;

    /** Its place in one store's menu; null when it has none there yet. */
    public function rankIn(string $storeId, string $categoryId): ?int;
}
