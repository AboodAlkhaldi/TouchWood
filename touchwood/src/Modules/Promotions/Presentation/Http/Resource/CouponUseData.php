<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One use of a coupon (promotions.md §4.1 M2, Uses): by the order's public number, never its id. */
#[TypeScript]
final class CouponUseData extends Data
{
    public function __construct(
        public int $id,
        public string $orderNumber,
        public CustomerNameData $customer,
        public MoneyData $discount,
        public string $usedAt,
    ) {}
}
