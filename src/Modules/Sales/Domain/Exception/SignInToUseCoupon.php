<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A guest's coupon code (answer 12).
 */
final class SignInToUseCoupon extends SalesError
{
    public function __construct()
    {
        parent::__construct('A guest cannot use a coupon.');
    }

    public function type(): string
    {
        return 'sales.sign_in_to_use_coupon';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
