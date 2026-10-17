<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command;

use DateTimeImmutable;
use Modules\Promotions\Domain\Model\CouponTerms;

/**
 * What staff fill in for a coupon but its code and store (promotions.md §1.1, §1.2), as the coupon
 * editor sends it: amounts in the store currency's smallest unit, the include and exclude lists as
 * rules. Checked by `CouponTerms` only after the permission.
 */
final readonly class CouponFields
{
    /**
     * @param  list<array{kind: string, target: string, id: string}>  $rules
     */
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public string $eligibility,
        public string $discountKind,
        public ?int $percent,
        public ?int $capMinor,
        public ?int $amountMinor,
        public ?int $minimumMinor,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public ?int $maxUsesPerCustomer,
        public ?int $maxUsesTotal,
        public bool $allowWithPoints,
        public bool $onReducedLines,
        public bool $forRetail,
        public bool $forWholesale,
        public bool $forPublic,
        public bool $forCompany,
        public array $rules,
    ) {}

    public function terms(string $currencyCode): CouponTerms
    {
        return CouponTerms::of(
            $this->nameAr, $this->nameEn, $this->eligibility, $this->discountKind, $this->percent, $this->capMinor,
            $this->amountMinor, $currencyCode, $this->minimumMinor, $this->startsAt, $this->endsAt,
            $this->maxUsesPerCustomer, $this->maxUsesTotal, $this->allowWithPoints, $this->onReducedLines,
            $this->forRetail, $this->forWholesale, $this->forPublic, $this->forCompany, $this->rules,
        );
    }
}
