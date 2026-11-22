<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of Marketing › Coupons (promotions.md §4.1 M1). `eligibility` PUBLIC or ASSIGNED,
 * `discountKind` PERCENT or FIXED, `state` SCHEDULED, ACTIVE, ENDED or INACTIVE (§4); moments ISO.
 */
#[TypeScript]
final class CouponRowData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $eligibility,
        public string $discountKind,
        public ?int $percent,
        public ?MoneyData $amount,
        public ?MoneyData $cap,
        public string $startsAt,
        public ?string $endsAt,
        public int $usesTotal,
        public ?int $maxUsesTotal,
        public string $state,
    ) {}
}
