<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\ValueObject\CategoryAudience;

/**
 * Who sees each category in each store (catalog.md §1.13, §5.2, amendment 14) — each store's rows,
 * **named in every call that reads or writes one store** (amendment 4(h)'s rule for a store's rows).
 * A category a store has no row for is seen by both kinds of account there. Read across stores in
 * one place only, where the rule needs every store: a sub-category added or moved takes its new
 * parent's switches in every store (P24). Written under the categories' lock.
 */
interface CategoryAudienceRepository
{
    /**
     * These categories' switches in the store — every id given, a category with no row there seen by
     * both.
     *
     * @param  list<string>  $categoryIds
     * @return array<string, CategoryAudience> keyed by category id
     */
    public function in(string $storeId, array $categoryIds): array;

    /**
     * Writes these categories' switches in the store.
     *
     * @param  array<string, CategoryAudience>  $audiences  category id => its switches there
     */
    public function set(string $storeId, array $audiences): void;

    /**
     * Every store's own switches of these categories — only the rows there are: a store with none for
     * a category is seen by both there. Across stores, for P24 alone.
     *
     * @param  list<string>  $categoryIds
     * @return array<string, array<string, CategoryAudience>> store id => category id => its switches
     */
    public function inEveryStore(array $categoryIds): array;
}
