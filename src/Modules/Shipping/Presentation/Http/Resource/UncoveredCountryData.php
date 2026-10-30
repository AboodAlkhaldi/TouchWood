<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A ship-to country's places no switched-on carrier serves - the biggest each time (§1.6, R13). */
#[TypeScript]
final class UncoveredCountryData extends Data
{
    /**
     * @param  list<PricePlaceData>  $places
     */
    public function __construct(
        public string $code,
        public array $places,
    ) {}
}
