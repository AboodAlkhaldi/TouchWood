<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

use DateTimeImmutable;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\DiscountKind;
use Shared\Domain\ValueObject\Money;

/**
 * A store's coupon as it is now (promotions.md §1.1, §5): read whole, with its rules. Its state —
 * scheduled, active, ended, inactive — is never stored: it follows from its dates, uses and switch (§4).
 * A percentage coupon has a percent and maybe a cap; a fixed one an amount — the database holds that.
 */
final readonly class Coupon
{
    /**
     * @param  list<CouponRule>  $rules
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public string $code,
        public string $nameAr,
        public string $nameEn,
        public CouponEligibility $eligibility,
        public DiscountKind $discountKind,
        public ?int $percent,
        public ?Money $cap,
        public ?Money $amount,
        public string $currencyCode,
        public ?Money $minimum,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public ?int $maxUsesPerCustomer,
        public ?int $maxUsesTotal,
        public int $usesTotal,
        public bool $allowWithPoints,
        public bool $onReducedLines,
        public bool $forRetail,
        public bool $forWholesale,
        public bool $forPublic,
        public bool $forCompany,
        public bool $active,
        public array $rules,
    ) {}
}
