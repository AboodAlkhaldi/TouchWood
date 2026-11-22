<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * **The store's top categories as tiles** (content.md §2.3 C4; catalog.md amendment 41(c), built as 46(b)), for the
 * home page's category row: the top of the menu as it is offered to the shopper looking, in the store's order, each
 * with its photo's sizes (§1.5's category image; none while it has none or its sizes are not ready). Read from the
 * menu's cached copy, so the only query is the one read of the photos' addresses.
 */
interface ShopCategoryTiles
{
    /**
     * A language but Arabic or English is refused, as on the shop's own pages.
     *
     * @param  int  $limit  1 to 12
     * @return list<array{name: string, slug: string, photo: array<string, array<string, string>>|null}>
     */
    public function read(StoreId $store, string $locale, int $limit): array;
}
