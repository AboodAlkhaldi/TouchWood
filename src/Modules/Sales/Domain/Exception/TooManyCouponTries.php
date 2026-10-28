<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Five codes that name no coupon in ten minutes (sales.md §1.1, answer 50): the box
 * takes no code until the window passes, a right one included.
 */
final class TooManyCouponTries extends SalesError
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many coupon codes that name no coupon.');
    }

    public function type(): string
    {
        return 'sales.too_many_coupon_tries';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }

    public function context(): array
    {
        return ['minutes' => max(1, (int) ceil($this->retryAfterSeconds / 60))];
    }
}
