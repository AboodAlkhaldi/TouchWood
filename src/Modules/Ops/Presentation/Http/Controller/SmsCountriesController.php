<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ops\Application\Command\AddSmsCountry\AddSmsCountry;
use Modules\Ops\Application\Command\AddSmsCountry\AddSmsCountryHandler;
use Modules\Ops\Application\Command\ChangeSmsCountryCallingCode\ChangeSmsCountryCallingCode;
use Modules\Ops\Application\Command\ChangeSmsCountryCallingCode\ChangeSmsCountryCallingCodeHandler;
use Modules\Ops\Application\Command\ChangeSmsCountryProvider\ChangeSmsCountryProvider;
use Modules\Ops\Application\Command\ChangeSmsCountryProvider\ChangeSmsCountryProviderHandler;
use Modules\Ops\Application\Command\RemoveSmsCountry\RemoveSmsCountry;
use Modules\Ops\Application\Command\RemoveSmsCountry\RemoveSmsCountryHandler;
use Modules\Ops\Application\Query\ListSmsCountries\ListSmsCountries;
use Modules\Ops\Application\Query\ListSmsCountries\ListSmsCountriesHandler;
use Modules\Ops\Application\Query\ListSmsCountries\SmsCountryRow;
use Modules\Ops\Presentation\Http\Resource\SmsCountriesData;
use Modules\Ops\Presentation\Http\Resource\SmsCountryData;

/*
| The SMS countries in the panel (ops.md §3, §4; access.md amendment 90): the list, add, change a provider
| or a calling code, remove. JSON, for a screen still to come; every answer is the whole list after it.
| Who may is Ops' answer: `ops.sms_countries.manage`, reserved to Super Admins, in each handler.
*/
final readonly class SmsCountriesController
{
    public function __construct(
        private ListSmsCountriesHandler $list,
    ) {}

    public function index(): JsonResponse
    {
        return $this->countries();
    }

    public function store(Request $request, AddSmsCountryHandler $add): JsonResponse
    {
        $add->handle(new AddSmsCountry(Inputs::string($request, 'country_code'), Inputs::string($request, 'calling_code'), Inputs::string($request, 'provider')));

        return $this->countries(201);
    }

    public function provider(Request $request, string $country, ChangeSmsCountryProviderHandler $change): JsonResponse
    {
        $change->handle(new ChangeSmsCountryProvider($country, Inputs::string($request, 'provider')));

        return $this->countries();
    }

    public function callingCode(Request $request, string $country, ChangeSmsCountryCallingCodeHandler $change): JsonResponse
    {
        $change->handle(new ChangeSmsCountryCallingCode($country, Inputs::string($request, 'calling_code')));

        return $this->countries();
    }

    public function remove(string $country, RemoveSmsCountryHandler $remove): JsonResponse
    {
        $remove->handle(new RemoveSmsCountry($country));

        return $this->countries();
    }

    private function countries(int $status = 200): JsonResponse
    {
        $locale = app()->getLocale();
        $list = $this->list->handle(new ListSmsCountries($locale));

        return new JsonResponse((new SmsCountriesData(
            array_map(static fn (SmsCountryRow $row): SmsCountryData => new SmsCountryData($row->countryCode, $row->name->in($locale), $row->callingCode, $row->provider), $list->countries),
            $list->providers,
        ))->toArray(), $status);
    }
}
