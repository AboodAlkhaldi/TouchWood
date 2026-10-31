<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

/** The reports (ops.md §1.10), by the name their address carries. */
enum ReportKind: string
{
    /** Per day or month and store: orders, revenue net of refunds, VAT, shipping, refunds; by category. */
    case Revenue = 'revenue';

    /** Per size: units and revenue without VAT. */
    case ProductSales = 'product-sales';

    /** Per size: in stock, held, available, low, out - now. Also needs `inventory.stock.manage` (§3). */
    case Stock = 'stock';

    /** Per phrase: searches, searches with no results; never more than twelve months (§1.10, §7). */
    case Searches = 'searches';

    /** Per product: the customers who saved it - now. */
    case Favourites = 'favourites';

    /** Whether the report covers a range of days; Stock and Favourites are as they stand now. */
    public function hasRange(): bool
    {
        return $this !== self::Stock && $this !== self::Favourites;
    }
}
