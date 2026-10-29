<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * C3 · A carrier (shipping.md §4.1 part 1, Q4): its details now; part 2 adds its price groups.
 */
#[TypeScript]
final class CarrierPage extends Data
{
    /**
     * @param  string  $countryName  the carrier's country, in the page's language
     */
    public function __construct(
        public CarrierData $carrier,
        public string $countryName,
        public bool $mayManage,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
    ) {}
}
