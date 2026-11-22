<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Home;

use DateTimeImmutable;
use Shared\Domain\ValueObject\StoreId;

/**
 * The reads only Sales' own Home sections need (dashboard.md §6.2), beside the daily report's in `SalesReports`:
 * kept inside Sales, since nothing outside it reads them. Reads only, with no permission of their own - the
 * cards are offered by Home's rule and ask nothing more. Test orders never count.
 */
interface SalesHomeReads
{
    /**
     * **Sales by who ordered** (§2 D, §3.1) from `from` up to `to` (not included): what `SalesReports::revenueByDay`
     * counts as revenue over the same orders, individuals' and companies' apart. One query.
     */
    public function byAudience(StoreId $store, string $currency, DateTimeImmutable $from, DateTimeImmutable $to): SalesByAudience;

    /** **The store's orders as they stand now** (§2 E, D2), each as the Orders list's tab counts it. One query. */
    public function ordersNow(StoreId $store): OrdersNow;

    /**
     * **The orders placed last** (§2 G, D4), newest first, `limit` 1 to 50, with each one's first line. Two queries.
     *
     * @return list<RecentOrder>
     */
    public function recentOrders(StoreId $store, int $limit): array;

    /** **The returns waiting** (§2 J, D6): asked and not yet finished - asked, accepted or received. One query. */
    public function returnsWaiting(StoreId $store): int;
}
