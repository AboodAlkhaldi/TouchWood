<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a coupon already used, removing an assignment already used, or lowering a coupon's total
 * uses below those made (promotions.md §7, §1.1, §1.4).
 */
final class CouponInUse extends PromotionsError
{
    public function __construct()
    {
        parent::__construct('The coupon has been used.');
    }

    public function type(): string
    {
        return 'promotions.coupon_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
