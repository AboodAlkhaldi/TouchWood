<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * The facts Catalog's listing needs but does not own, **pushed in by the modules that own them**,
 * inside their own transaction, so a list is never stale (catalog.md §2.2): whether a variant can be
 * ordered now (Inventory, stage 5), the price shown (Pricing, stage 5), the sales rank (Sales,
 * stage 6). **Declared in step 5; Catalog implements it with stage 5**, which first calls it and
 * decides, for one, which price a card shows (owner, 2026-10-05, amendment 5(i)). Until then every
 * listed product is orderable as §1.3 says, with no price and no rank.
 */
interface ListingFacts
{
    /**
     * @param  list<string>  $variantIds
     */
    public function orderable(StoreId $store, array $variantIds, bool $orderable): void;

    /**
     * @param  array<string, Money|null>  $prices  variant id => its price there, or none
     */
    public function prices(StoreId $store, array $prices): void;

    /**
     * @param  array<string, int>  $ranks  product id => its rank there, 1 the best-selling
     */
    public function salesRanks(StoreId $store, array $ranks): void;
}
