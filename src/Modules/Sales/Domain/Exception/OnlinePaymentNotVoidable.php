<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/** A real online payment is never voided: a mistake is fixed by a refund (§9.5 #13). */
final class OnlinePaymentNotVoidable extends SalesError
{
    public function __construct()
    {
        parent::__construct('A real online payment is never voided.');
    }

    public function type(): string
    {
        return 'sales.online_payment_not_voidable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
