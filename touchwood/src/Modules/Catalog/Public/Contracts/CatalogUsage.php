<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Modules\Catalog\Public\Dto\CatalogUseDto;
use Modules\Catalog\Public\Enums\CatalogItem;

/**
 * Implemented by every module that holds on to Catalog's categories, brands, products or variants
 * (amendment 19; promotions.md §1.2): a coupon's include and exclude lists, a gift level's variant.
 * Before Catalog deletes one, it asks every registered module where it is used; any use refuses the
 * delete, with what uses it — a rule is never dropped silently, as dropping an exclusion would widen
 * what a coupon covers. As Platform's `MediaUsage`.
 *
 * Asked inside the delete's transaction, under Catalog's lock on the list concerned. The module's own
 * foreign key, ON DELETE RESTRICT, catches a reference written meanwhile.
 */
interface CatalogUsage
{
    /**
     * Every place this module uses any of these items; empty when it uses none.
     *
     * @param  list<string>  $ids  lower-case ids of items of that kind
     * @return list<CatalogUseDto>
     */
    public function usesOf(CatalogItem $item, array $ids): array;
}
