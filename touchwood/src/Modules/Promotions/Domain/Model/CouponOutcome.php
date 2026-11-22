<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Domain\ValueObject\Money;

/**
 * What the coupon rules decided (promotions.md §1.3): the discount and its share of each line, in the
 * lines' order, 0 for a line not covered — or the refusal.
 */
final readonly class CouponOutcome
{
    /**
     * @param  list<Money>  $shares
     */
    private function __construct(
        public ?Money $discount,
        public array $shares,
        public ?CouponRefusal $refusal,
    ) {}

    /**
     * @param  list<Money>  $shares
     */
    public static function taken(Money $discount, array $shares): self
    {
        return new self($discount, $shares, null);
    }

    public static function refused(CouponRefusal $refusal): self
    {
        return new self(null, [], $refusal);
    }
}
