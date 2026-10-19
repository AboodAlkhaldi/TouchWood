<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A coupon as its form shows it (promotions.md §4.1 M2): amounts as the decimal text a box holds
 * ("100.00"), moments ISO, null where nothing is set; `state`, `usesTotal` and `assignedCount` read only.
 */
#[TypeScript]
final class CouponFormData extends Data
{
    /**
     * @param  list<RuleData>  $rules
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $nameAr,
        public string $nameEn,
        public string $eligibility,
        public string $discountKind,
        public ?int $percent,
        public ?string $cap,
        public ?string $amount,
        public ?string $minimum,
        public string $startsAt,
        public ?string $endsAt,
        public ?int $maxUsesPerCustomer,
        public ?int $maxUsesTotal,
        public bool $allowWithPoints,
        public bool $onReducedLines,
        public bool $forRetail,
        public bool $forWholesale,
        public bool $forPublic,
        public bool $forCompany,
        public array $rules,
        public bool $active,
        public string $state,
        public int $usesTotal,
        public int $assignedCount,
    ) {}
}
