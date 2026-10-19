<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A change its state does not allow; voiding a payment the refunds still rest on (answer 42).
 */
final class InvalidOrderChange extends SalesError
{
    public function __construct()
    {
        parent::__construct('The order\'s state does not allow this change.');
    }

    public function type(): string
    {
        return 'sales.invalid_order_change';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
