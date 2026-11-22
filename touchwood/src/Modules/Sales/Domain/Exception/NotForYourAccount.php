<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A wholesale line for an individual, or a product in a category hidden from the account's type (§1.1, answer 32).
 */
final class NotForYourAccount extends SalesError
{
    public function __construct()
    {
        parent::__construct('This product is not sold to this account.');
    }

    public function type(): string
    {
        return 'sales.not_for_your_account';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
