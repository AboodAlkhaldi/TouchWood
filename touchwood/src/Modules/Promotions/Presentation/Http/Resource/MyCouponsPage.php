<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * M5 · the shop account's Coupons tab (promotions.md §4.1): the coupons given to the customer that they
 * can use now in this store, soonest to end first; the store's zone for the dates.
 */
#[TypeScript]
final class MyCouponsPage extends Data
{
    /**
     * @param  list<MyCouponData>  $coupons
     */
    public function __construct(
        public array $coupons,
        public string $storeTimezone,
    ) {}
}
