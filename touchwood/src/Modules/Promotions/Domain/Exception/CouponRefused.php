<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Exception;

use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Domain\Error\ErrorCategory;

/**
 * Placing an order with a coupon no longer usable (promotions.md §7) — with the reason a quote gives —
 * or on an order that already used another coupon: one coupon per order (§1.2). A coupon still usable
 * but now taking off something else is `CouponChanged`.
 */
final class CouponRefused extends PromotionsError
{
    /** The reason the caller reads when the order already has another coupon: no quote gives it. */
    public const string ANOTHER_COUPON = 'ANOTHER_COUPON';

    private function __construct(public readonly ?CouponRefusal $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(CouponRefusal $reason): self
    {
        return new self($reason, "The coupon cannot be used on this order: {$reason->value}.");
    }

    public static function anotherCoupon(): self
    {
        return new self(null, 'The order already used another coupon.');
    }

    public function type(): string
    {
        return 'promotions.coupon_refused';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['reason' => $this->reason->value ?? self::ANOTHER_COUPON];
    }
}
