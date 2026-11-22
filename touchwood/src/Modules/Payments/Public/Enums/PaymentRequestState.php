<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Enums;

/** A staff request for the rest of an order (payments.md §4). */
enum PaymentRequestState: string
{
    case Open = 'OPEN';
    case Paid = 'PAID';
    case Expired = 'EXPIRED';
    case Withdrawn = 'WITHDRAWN';
    /** A currency refused (§1.5): nothing written on the order, needing attention in the Payments list. */
    case NotAccepted = 'NOT_ACCEPTED';
}
