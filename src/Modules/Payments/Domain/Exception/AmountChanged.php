<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A request for an amount other than the open request's: Sales must withdraw it first (payments.md §7).
 */
final class AmountChanged extends PaymentsError
{
    public function __construct()
    {
        parent::__construct('An open payment request asks for another amount.');
    }

    public function type(): string
    {
        return 'payments.amount_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
