<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The SMS countries, by name in the page's language, and the providers a country may choose (ops.md §3, §4). */
#[TypeScript]
final class SmsCountriesData extends Data
{
    /**
     * @param  list<SmsCountryData>  $countries
     * @param  list<string>  $providers
     */
    public function __construct(
        public array $countries,
        public array $providers,
    ) {}
}
