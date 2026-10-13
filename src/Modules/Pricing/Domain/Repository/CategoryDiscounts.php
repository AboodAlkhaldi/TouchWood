<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

use DateTimeImmutable;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Domain\ValueObject\StoreId;

/**
 * Category discounts and the products each leaves out (pricing.md §5 `pricing.category_discounts`,
 * `pricing.category_discount_exclusions`). Every query names its store, but `find`, which finds the
 * discount's store with it, and the daily check's.
 */
interface CategoryDiscounts
{
    public function find(string $discountId): ?CategoryDiscount;

    /** A new discount or a changed one, with the products it leaves out. */
    public function save(CategoryDiscount $discount): void;

    public function remove(string $discountId): void;

    /**
     * @return list<CategoryDiscount> every discount in the store not ended by then
     */
    public function notEnded(StoreId $store, DateTimeImmutable $at): array;

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
