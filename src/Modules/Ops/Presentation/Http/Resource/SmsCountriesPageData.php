<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The SMS Countries page (ops.md §4.15 O10): the list, the providers, and every country it may add. */
#[TypeScript]
final class SmsCountriesPageData extends Data
{
    /**
     * @param  list<SmsCountryData>  $countries
     * @param  list<string>  $providers
     * @param  list<SmsCountryOptionData>  $options
     */
    public function __construct(
        public array $countries,
        public array $providers,
        public array $options,
    ) {}
}
