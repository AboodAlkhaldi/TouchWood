<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Pricing's part of a product's page in the shop, `pricing.wholesale` (catalog.md amendment 35): each
 * size here a company or a guest can buy wholesale and that is priced, keyed by its id, in the product's
 * order, with its wholesale price and its bands.
 */
#[TypeScript]
final class ShopWholesaleData extends Data
{
    /**
     * @param  array<string, ShopWholesaleSizeData>  $sizes  variant id => its wholesale price and bands
     */
    public function __construct(
        public array $sizes,
    ) {}
}
