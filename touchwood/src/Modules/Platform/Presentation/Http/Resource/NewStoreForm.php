<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the Add Store form offers (platform.md §9.7 #3, #4; owner, 2026-10-06): sent only to whoever
 * may open a store - a Super Admin.
 */
#[TypeScript]
final class NewStoreForm extends Data
{
    /**
     * @param  list<FreeCurrencyRow>  $freeCurrencies  the currencies no store uses: one currency, one store
     * @param  list<StoreCountryOption>  $countries  every country, named in the panel's language
     * @param  array<string, string>  $zones  the time zone of each country with only one, to fill the field when it is chosen
     * @param  list<int>  $exponents  the numbers of decimal places a new currency may have
     * @param  int  $nextPosition  ten after the last store, as a new type's position is
     */
    public function __construct(
        public array $freeCurrencies,
        public array $countries,
        public array $zones,
        public array $exponents,
        public int $nextPosition,
    ) {}
}
