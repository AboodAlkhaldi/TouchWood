<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;
use Modules\Promotions\Public\Enums\DiscountKind;
use Shared\Domain\ValueObject\Money;

/**
 * A coupon given to me that I can use now, for the account centre (promotions.md §3, "My coupons"): its
 * code to type, what it takes off, its minimum, until when, and how many uses I have left (null: no
 * limit for me).
 */
final readonly class MyCouponRow
{
    public function __construct(
        public string $code,
        public string $nameAr,
        public string $nameEn,
        public DiscountKind $discountKind,
        public ?int $percent,
        public ?Money $cap,
        public ?Money $amount,
        public ?Money $minimum,
        public ?DateTimeImmutable $endsAt,
        public ?int $usesLeft,
    ) {}
}
