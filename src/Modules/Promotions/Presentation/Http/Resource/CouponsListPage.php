<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * M1 · Marketing › Coupons (promotions.md §4.1): newest first, the next page's cursor null on the last;
 * `state` and `code` the filters it was drawn with.
 */
#[TypeScript]
final class CouponsListPage extends Data
{
    /**
     * @param  list<CouponRowData>  $coupons
     * @param  list<StoreOptionData>  $stores
     */
    public function __construct(
        public array $coupons,
        public ?string $nextAt,
        public ?string $nextId,
        public ?string $state,
        public ?string $code,
        public bool $mayManage,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public string $storeTimezone,
        public array $stores,
    ) {}
}
