<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Enums;

/** Why a line of the cart cannot be ordered as it is now (sales.md §1.1, §1.2 step 2) — the cart marks it. */
enum CartLineProblem: string
{
    /** Not sold here now: switched off, not orderable, not available now, or not in this mode. */
    case NotAvailable = 'NOT_AVAILABLE';
    /** Wholesale for an account that is not an approved company, or its category closed to this account. */
    case NotForYourAccount = 'NOT_FOR_YOUR_ACCOUNT';
    case BelowMinimum = 'BELOW_MINIMUM';
    case AboveMaximum = 'ABOVE_MAXIMUM';
    /** Fewer free than asked (the pieces of a size counted over both modes). */
    case OutOfStock = 'OUT_OF_STOCK';
    /** No price in this store now. */
    case NotPriced = 'NOT_PRICED';
}
