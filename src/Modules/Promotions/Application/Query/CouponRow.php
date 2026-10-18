<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\CouponState;
use Modules\Promotions\Public\Enums\DiscountKind;
use Shared\Domain\ValueObject\Money;

/** One coupon in a store's list (promotions.md §3): what it takes off, its dates, its uses, its state now. */
final readonly class CouponRow
{
    public function __construct(
        public string $id,
        public string $code,
        public string $nameAr,
        public string $nameEn,
        public CouponEligibility $eligibility,
        public DiscountKind $discountKind,
        public ?int $percent,
        public ?Money $amount,
        public string $currencyCode,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public int $usesTotal,
        public ?int $maxUsesTotal,
        public bool $active,
        public CouponState $state,
        public DateTimeImmutable $createdAt,
    ) {}
}
