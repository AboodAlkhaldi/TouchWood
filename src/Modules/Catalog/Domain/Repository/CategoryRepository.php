<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
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
     * Its place among its siblings in each of these stores (catalog.md §1.5): a store's own order.
     *
     * @param  list<string>  $storeIds
     */
    public function placeIn(string $categoryId, array $storeIds, int $rank): void;

    /**
     * Places in one store's menu, written at once (catalog.md §1.5).
     *
     * @param  array<string, int>  $ranks  a category's id => its place there
     */
    public function placeEach(string $storeId, array $ranks): void;

    /**
     * Puts it **last among its siblings** — the categories under this parent, itself left out — in
     * each of these stores (catalog.md §1.5, amendment 16(d)): after the place each sibling shows
     * there, its own or, until the store's admins place it, the base store's (amendment 5(a)).
     * Should that pass the cap, the siblings are numbered again 1, 2, 3 … in that store first, each
     * keeping its place among the others.
     *
     * @param  list<string>  $storeIds
     * @return list<array{string, string, ?int, int}> every place it wrote, for the audit log: the category's id, the store's, its own place there before (null for none) and now
     *
     * @throws InvalidCatalogAttribute when the parent already holds as many categories as places go
     */
    public function placeLast(string $categoryId, ?string $parentId, array $storeIds, ?string $baseStoreId): array;

    /**
     * The categories under this parent in the order one store's menu shows them — the place each
     * shows there, its own or else the base store's (amendment 5(a)), those with neither last, then
     * the English name, then the id — the order the Categories screen shows.
     *
     * @return array<string, array{?int, ?int}> a category's id => [its own place there, the place it shows there]
     */
    public function siblingsIn(string $storeId, ?string $baseStoreId, ?string $parentId): array;

    /** Its place in one store's menu; null when it has none there yet. */
    public function rankIn(string $storeId, string $categoryId): ?int;
}
