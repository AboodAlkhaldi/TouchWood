<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One country the store ships to, with its carriers in their order - none when it has none (Q11). */
#[TypeScript]
final class CarrierCountryData extends Data
{
    /**
     * @param  string  $name  in the page's language
     * @param  bool  $own  the store's own country, shown first and marked Own
     * @param  list<CarrierData>  $carriers
     */
    public function __construct(
        public string $code,
        public string $name,
        public bool $own,
        public array $carriers,
    ) {}
}
