<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/** A live try on this cart: only the same quote and session again gets it (payments.md §1.4 step 3). */
final class PaymentInProgress extends SalesError
{
    public function __construct()
    {
        parent::__construct('A payment is already in progress in another window.');
    }

    public function type(): string
    {
        return 'sales.payment_in_progress';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
