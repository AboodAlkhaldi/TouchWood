<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListCategoryDiscounts;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Application\Unauthorized;

/**
 * **The store's category discounts** (`ListCategoryDiscounts`, pricing.md §3), for whoever holds any of
 * Pricing's three jobs there (owner, 2026-10-08): a page at a time, the latest start first. A read: no
 * lock, nothing written.
 */
final readonly class ListCategoryDiscountsHandler
{
    public function __construct(
        private ReadAccess $access,
        private CategoryDiscounts $discounts,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput
     */
    public function handle(ListCategoryDiscounts $query): DiscountList
    {
        $store = $this->access->authorize($query->storeId)->storeId();
        [$discounts, $total] = $this->discounts->page($store, $query->page, ListCategoryDiscounts::PER_PAGE);
        $now = CarbonImmutable::now()->toDateTimeImmutable();

        return new DiscountList(array_map(static fn (CategoryDiscount $discount): DiscountRow => self::row($discount, $now), $discounts), $total);
    }

    public static function row(CategoryDiscount $discount, DateTimeImmutable $now): DiscountRow
    {
        return new DiscountRow(
            $discount->id,
            $discount->categoryId,
            $discount->percentBasisPoints,
            $discount->amount?->minorUnits,
            $discount->amount?->currencyCode,
            $discount->lines->value,
            $discount->startsAt->format(DateTimeInterface::ATOM),
            $discount->endsAt?->format(DateTimeInterface::ATOM),
            $discount->alwaysWins,
            $discount->scheduled($now) ? 'SCHEDULED' : ($discount->ended($now) ? 'ENDED' : 'RUNNING'),
            $discount->excludedProductIds,
        );
    }
}
