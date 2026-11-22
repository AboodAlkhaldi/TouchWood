<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A session asked for in a store with no gateway on, or with its key refused (payments.md §7): the page
 * offers "staff will contact you".
 */
final class NoOnlinePayment extends PaymentsError
{
    public function __construct()
    {
        parent::__construct('This store does not take online payment now.');
    }

    public function type(): string
    {
        return 'payments.no_online_payment';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
