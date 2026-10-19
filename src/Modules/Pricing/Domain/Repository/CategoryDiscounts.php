<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

use DateTimeImmutable;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Modules\Pricing\Domain\ValueObject\WindowOrder;
use Modules\Pricing\Domain\ValueObject\WindowState;
use Shared\Domain\ValueObject\StoreId;

/**
 * Category discounts and the products each leaves out (pricing.md §5 `pricing.category_discounts`,
 * `pricing.category_discount_exclusions`). Every query names its store, but `find`, which finds the
 * discount's store with it, and the daily check's.
 */
interface CategoryDiscounts
{
    public function find(string $discountId): ?CategoryDiscount;

    /**
     * @param  list<string>  $discountIds
     * @return array<string, CategoryDiscount> by id; one that does not exist is left out
     */
    public function findMany(array $discountIds): array;

    /** A new discount or a changed one, with the products it leaves out. */
    public function save(CategoryDiscount $discount): void;

    public function remove(string $discountId): void;

    /**
     * @return list<CategoryDiscount> every discount in the store not ended by then
     */
    public function notEnded(StoreId $store, DateTimeImmutable $at): array;

    /**
     * @param  list<StoreId>  $stores
     * @return list<CategoryDiscount> every discount in these stores not ended by then
     */
    public function notEndedInStores(array $stores, DateTimeImmutable $at): array;

    /**
     * A page of the store's discounts for the screens (`ListCategoryDiscounts`, pricing.md §4.3.3 P3): in
     * one state or any, in the order chosen, keyset-paged as the sales are, with how many match.
     *
     * @param  array{int, string, string}|null  $after
     * @return array{list<CategoryDiscount>, int, array{int, string, string}|null}
     */
    public function list(StoreId $store, DateTimeImmutable $now, ?WindowState $state, WindowOrder $order, ?array $after, int $limit): array;

    /**
     * @return list<string> the stores with a discount not ended by then - where a product moving matters
     */
    public function storesWithDiscounts(DateTimeImmutable $at): array;

    /**
     * The daily safety check's read (§1.10): every discount, in any store, whose start or end fell
     * after `$from` and by `$to`.
     *
     * @return list<CategoryDiscount>
     */
    public function windowsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
