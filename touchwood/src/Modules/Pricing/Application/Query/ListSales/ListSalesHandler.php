<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListSales;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\ListCursor;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\Prices\SaleRows;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\WindowOrder;
use Modules\Pricing\Domain\ValueObject\WindowState;
use Shared\Application\Unauthorized;

/**
 * **The store's sales** (`ListSales`, pricing.md §3, §4.3.3 P2), for whoever holds any of Pricing's three
 * jobs there (owner, 2026-10-08): filtered by state, product or one sale; by state or newest added (Q7);
 * keyset-paged with the total; each row named and counted for the page (`SaleRows`). A fixed few queries
 * however many rows. A read: no lock, nothing written.
 */
final readonly class ListSalesHandler
{
    public function __construct(
        private ReadAccess $access,
        private Sales $sales,
        private SaleRows $rows,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(ListSales $query): SaleList
    {
        $store = $this->access->authorize($query->storeId);
        $state = $query->state === null ? null : (WindowState::tryFrom($query->state) ?? throw new InvalidPricingInput('state'));
        $order = WindowOrder::tryFrom($query->order) ?? throw new InvalidPricingInput('order');
        $now = CarbonImmutable::now()->toDateTimeImmutable();

        [$sales, $total, $next] = $this->sales->list($store->storeId(), $now, $state, $query->productId, $query->saleId, $order, ListCursor::decode($query->after), ListSales::PER_PAGE);

        return new SaleList($this->rows->rows($sales, $store, $now)[0], ListCursor::encode($next), $total);
    }
}
