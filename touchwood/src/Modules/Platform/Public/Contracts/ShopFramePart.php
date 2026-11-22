<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\StoreDto;

/**
 * What a module implements to put a part in the shop's frame (catalog.md §4.5; owner, 2026-10-07,
 * #9): Catalog's category menu and search box in the header of every shop page, the account pages'
 * too. Platform carries it with the page; it never knows what it holds.
 *
 * Asked on every full shop page, so it answers from a cache, not from its tables.
 */
interface ShopFramePart
{
    /**
     * What the frame draws for this store, in this language, already in it: plain data only.
     *
     * @return array<string, mixed>
     */
    public function partFor(StoreDto $store, string $locale): array;
}
