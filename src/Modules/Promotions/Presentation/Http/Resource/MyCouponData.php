<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A coupon given to the customer, on their account's Coupons tab (promotions.md §4.1 M5): its code to
 * type, its name in the page's language, what it takes off, the minimum order, until when (null: no end
 * date), and the uses left (null: no limit).
 */
#[TypeScript]
final class MyCouponData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public string $discountKind,
        public ?int $percent,
        public ?MoneyData $cap,
        public ?MoneyData $amount,
        public ?MoneyData $minimum,
        public ?string $endsAt,
        public ?int $usesLeft,
    ) {}
}
