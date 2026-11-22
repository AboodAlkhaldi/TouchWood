<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/** Only an individual's live order, in a store whose gateway is on and in the order's own mode (payments.md §1.5). */
final class PaymentRequestNotAllowed extends SalesError
{
    public function __construct()
    {
        parent::__construct('This order cannot be paid through a payment request.');
    }

    public function type(): string
    {
        return 'sales.payment_request_not_allowed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
