<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/** A payment is open on the order (payments.md §1.5): staff's edits, hand recording and the customer's cancel wait for its answer. */
final class PaymentBeingConfirmed extends SalesError
{
    public function __construct()
    {
        parent::__construct('A payment is being confirmed.');
    }

    public function type(): string
    {
        return 'sales.payment_being_confirmed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
