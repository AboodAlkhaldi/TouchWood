<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A place in a carrier's price, or among the places no carrier serves (shipping.md §4.1 part 2): its name
 * in the page's language, what it is under ("Riyadh · Riyadh Region"), and whether it is retired (R16).
 */
#[TypeScript]
final class PricePlaceData extends Data
{
    /**
     * @param  string  $kind  REGION, CITY or DISTRICT
     * @param  string|null  $under  the city a district is in, or the region a city is in
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public ?string $under,
        public bool $retired,
    ) {}
}
