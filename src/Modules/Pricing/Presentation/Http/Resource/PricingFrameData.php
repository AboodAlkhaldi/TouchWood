<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What every Pricing screen shows around its rows (pricing.md §4.3.1, §4.3.2): the store shown and the
 * filter's stores, whether it is off (a Super Admin's, marked Off) or wired to a provider (the Note), its
 * discount ceiling and whether the reader may change it (the link under the subtitle, Q11), the jobs the
 * reader holds there (`PricingActionsForReader`), the currency's decimals and letters for a price box,
 * and the reasons a button is disabled.
 */
#[TypeScript]
final class PricingFrameData extends Data
{
    /**
     * @param  list<PricingStoreOptionData>  $stores
     */
    public function __construct(
        public string $storeCode,
        public string $storeName,
        public array $stores,
        public bool $off,
        public bool $wired,
        public int $ceilingPercent,
        public bool $maySetCeiling,
        public bool $mayEditPrices,
        public bool $mayEditWholesale,
        public bool $mayManageDiscounts,
        public bool $mayViewProducts,
        public int $decimals,
        public string $currency,
        public PricingReasonsData $reasons,
    ) {}
}
