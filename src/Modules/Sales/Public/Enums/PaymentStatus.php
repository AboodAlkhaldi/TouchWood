<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Enums;

/**
 * An order's payment, worked out from the money recorded (sales.md §1.4): what is due against what it holds
 * (paid less refunded).
 */
enum PaymentStatus: string
{
    /** Nothing due, nothing ever paid. */
    case NothingDue = 'NOTHING_DUE';
    /** Holds nothing, something due. */
    case Unpaid = 'UNPAID';
    case PartlyPaid = 'PARTLY_PAID';
    /** Holds exactly what is due, above 0. */
    case Paid = 'PAID';
    /** Holds more than is due: a refund owed. */
    case RefundDue = 'REFUND_DUE';
    /** Nothing due or held, money having been paid and paid back. */
    case Refunded = 'REFUNDED';
}
