<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListCategoryDiscounts;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\DiscountRows;
use Modules\Pricing\Application\Prices\ListCursor;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\ValueObject\WindowOrder;
use Modules\Pricing\Domain\ValueObject\WindowState;
use Shared\Application\Unauthorized;

/**
 * **The store's category discounts** (`ListCategoryDiscounts`, pricing.md §3, §4.3.3 P3), for whoever holds
 * any of Pricing's three jobs there (owner, 2026-10-08): in one state or any, by state or newest added (Q7),
 * keyset-paged with the total; each category with its path, each left-out product named. A fixed few
 * queries however many rows. A read: no lock, nothing written.
 */
final readonly class ListCategoryDiscountsHandler
{
    public function __construct(
        private ReadAccess $access,
        private CategoryDiscounts $discounts,
        private DiscountRows $rows,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(ListCategoryDiscounts $query): DiscountList
    {
        $store = $this->access->authorize($query->storeId);
        $state = $query->state === null ? null : (WindowState::tryFrom($query->state) ?? throw new InvalidPricingInput('state'));
        $order = WindowOrder::tryFrom($query->order) ?? throw new InvalidPricingInput('order');
        $now = CarbonImmutable::now()->toDateTimeImmutable();

        [$discounts, $total, $next] = $this->discounts->list($store->storeId(), $now, $state, $order, ListCursor::decode($query->after), ListCategoryDiscounts::PER_PAGE);

        return new DiscountList($this->rows->rows($discounts, $store, $now), ListCursor::encode($next), $total);
    }
}
