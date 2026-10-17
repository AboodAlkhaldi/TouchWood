<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * At placement, the coupon's discount worked out again is not the one quoted — the coupon was changed
 * meanwhile — so checkout quotes again (promotions.md §7, §2.1).
 */
final class CouponChanged extends PromotionsError
{
    public function __construct()
    {
        parent::__construct('The coupon no longer takes off what was quoted.');
    }

    public function type(): string
    {
        return 'promotions.coupon_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
