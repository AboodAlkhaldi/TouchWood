<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * M2 · a coupon's page, or a new one's (`coupon` null), in one store (promotions.md §4.1). `tab` is the
 * one open: details, uses or customers. The uses and the customers are a page each, read only when their
 * tab is open; their cursors null on the last page. `categories` and `brands` are the rule picker's
 * choices; `products` what the picker's product search found, only when it searched. `ceilingPercent`
 * the store's discount ceiling (owner's Q15); `exponent` its currency's decimals.
 */
#[TypeScript]
final class CouponPage extends Data
{
    /**
     * @param  list<CouponUseData>  $uses
     * @param  list<AssignmentData>  $customers
     * @param  list<ChoiceData>  $categories
     * @param  list<ChoiceData>  $brands
     * @param  list<ChoiceData>  $products
     * @param  list<SegmentChoiceData>  $segments
     */
    public function __construct(
        public ?CouponFormData $coupon,
        public string $tab,
        public array $uses,
        public ?string $usesNextAt,
        public ?int $usesNextId,
        public array $customers,
        public ?string $customersNextAt,
        public ?string $customersNextId,
        public array $categories,
        public array $brands,
        public array $products,
        public array $segments,
        public int $ceilingPercent,
        public string $currency,
        public int $exponent,
        public bool $mayManage,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public string $storeTimezone,
    ) {}
}
