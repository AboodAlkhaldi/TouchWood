<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Pricing's part of a product's page in the shop, `pricing.wholesale` (catalog.md amendment 35): each
 * size here that sells wholesale and has bands, keyed by its id, with its bands, lowest start first.
 */
#[TypeScript]
final class ShopWholesaleData extends Data
{
    /**
     * @param  array<string, list<ShopBandData>>  $sizes  variant id => its bands
     */
    public function __construct(
        public array $sizes,
    ) {}
}
