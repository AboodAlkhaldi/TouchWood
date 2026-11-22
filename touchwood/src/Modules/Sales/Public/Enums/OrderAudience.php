<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Enums;

/**
 * Who an order is for (sales.md §1.2): `COMPANY` for a company approved in the store — the only company
 * that may order — `PUBLIC` for an individual. Passed to Promotions and Loyalty as their own enums, and
 * kept on the order.
 */
enum OrderAudience: string
{
    case Public = 'PUBLIC';
    case Company = 'COMPANY';
}
