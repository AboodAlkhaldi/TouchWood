<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One carrier as the carriers screens show it (shipping.md §4.1 part 1): its names in both languages,
 * its logo's thumbnail (null with none - the screen shows its initials, Q15), its contacts and tracking
 * link pattern, and whether it is on.
 */
#[TypeScript]
final class CarrierData extends Data
{
    public function __construct(
        public string $id,
        public string $countryCode,
        public string $nameAr,
        public string $nameEn,
        public ?string $logoMediaId,
        public ?string $logo,
        public ?string $phone,
        public ?string $website,
        public ?string $trackingPattern,
        public bool $active,
    ) {}
}
