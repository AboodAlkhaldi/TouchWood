<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/** No such try, or another customer's. */
final class CheckoutTryNotFound extends SalesError
{
    public function __construct()
    {
        parent::__construct('No such payment.');
    }

    public function type(): string
    {
        return 'sales.checkout_try_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
