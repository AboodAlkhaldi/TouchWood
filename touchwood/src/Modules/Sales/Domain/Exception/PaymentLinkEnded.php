<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/** No request open on the order, or one that no longer matches what it is missing (payments.md §1.5, §4.2). */
final class PaymentLinkEnded extends SalesError
{
    public function __construct()
    {
        parent::__construct('This payment link has ended.');
    }

    public function type(): string
    {
        return 'sales.payment_link_ended';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
