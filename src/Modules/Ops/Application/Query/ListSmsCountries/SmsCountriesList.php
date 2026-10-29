<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSmsCountries;

/**
 * The SMS countries, and the providers Ops has built - the only ones a country may choose (owner,
 * 2026-10-29).
 */
final readonly class SmsCountriesList
{
    /**
     * @param  list<SmsCountryRow>  $countries
     * @param  list<string>  $providers
     */
    public function __construct(
        public array $countries,
        public array $providers,
    ) {}
}
