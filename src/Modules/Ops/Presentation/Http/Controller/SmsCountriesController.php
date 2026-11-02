<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
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
use Modules\Ops\Domain\Exception\CallingCodeOverlaps;
use Modules\Ops\Domain\Exception\CallingCodeTaken;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\SmsCountryTaken;
use Modules\Ops\Presentation\Http\Resource\SmsCountriesData;
use Modules\Ops\Presentation\Http\Resource\SmsCountriesPageData;
use Modules\Ops\Presentation\Http\Resource\SmsCountryData;
use Modules\Ops\Presentation\Http\Resource\SmsCountryOptions;
use Shared\Domain\Error\DomainError;

/*
| The SMS countries in the panel (ops.md §3, §4.14, §4.15 O10; access.md amendment 90): the list, add, change a
| provider or a calling code, remove. A plain visit gets the page and a screen's own read the JSON; a change sent
| by the page goes back to it with its words (`Answers`), and any other answers the whole list as JSON.
| Who may is Ops' answer: `ops.sms_countries.manage`, reserved to Super Admins, in each handler.
*/
final readonly class SmsCountriesController
{
    /** @var list<string> */
    private const array WORDS = ['ops::admin_sms', 'ops::errors', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, ListSmsCountriesHandler $list): Response|JsonResponse
    {
        if ($request->expectsJson()) {
            return self::countries($list);
        }

        $data = self::data($list);

        return $this->page->render('Ops/Admin/SmsCountries', (new SmsCountriesPageData(
            $data->countries,
            $data->providers,
            SmsCountryOptions::in(app()->getLocale()),
        ))->toArray(), self::WORDS);
    }

    public function store(Request $request, AddSmsCountryHandler $add, ListSmsCountriesHandler $list): JsonResponse|RedirectResponse
    {
        return self::answer($request, $list, static fn () => $add->handle(new AddSmsCountry(Inputs::string($request, 'country_code'), Inputs::string($request, 'calling_code'), Inputs::string($request, 'provider'))), 'added', 201);
    }

    public function provider(Request $request, string $country, ChangeSmsCountryProviderHandler $change, ListSmsCountriesHandler $list): JsonResponse|RedirectResponse
    {
        return self::answer($request, $list, static fn () => $change->handle(new ChangeSmsCountryProvider($country, Inputs::string($request, 'provider'))), 'provider_changed');
    }

    public function callingCode(Request $request, string $country, ChangeSmsCountryCallingCodeHandler $change, ListSmsCountriesHandler $list): JsonResponse|RedirectResponse
    {
        return self::answer($request, $list, static fn () => $change->handle(new ChangeSmsCountryCallingCode($country, Inputs::string($request, 'calling_code'))), 'calling_code_changed');
    }

    public function remove(Request $request, string $country, RemoveSmsCountryHandler $remove, ListSmsCountriesHandler $list): JsonResponse|RedirectResponse
    {
        return self::answer($request, $list, static fn () => $remove->handle(new RemoveSmsCountry($country)), 'removed');
    }

    /** A change: back to the page with its words when the page sent it, otherwise the whole list. */
    private static function answer(Request $request, ListSmsCountriesHandler $list, Closure $change, string $toast, int $status = 200): JsonResponse|RedirectResponse
    {
        if (! Answers::fromPage($request)) {
            $change();

            return self::countries($list, $status);
        }

        try {
            $change();
        } catch (DomainError $error) {
            $field = match (true) {
                $error instanceof InvalidOpsInput => $error->attribute,
                $error instanceof SmsCountryTaken => 'country_code',
                $error instanceof CallingCodeTaken, $error instanceof CallingCodeOverlaps => 'calling_code',
                default => null,
            };

            return $field === null
                ? FormErrors::back($request, $error)
                : back()->withErrors([$field => FormErrors::message($error)]);
        }

        return back()->with('status', __("ops::admin_sms.toast.{$toast}"));
    }

    private static function countries(ListSmsCountriesHandler $list, int $status = 200): JsonResponse
    {
        return new JsonResponse(self::data($list)->toArray(), $status);
    }

    private static function data(ListSmsCountriesHandler $handler): SmsCountriesData
    {
        $locale = app()->getLocale();
        $list = $handler->handle(new ListSmsCountries($locale));

        return new SmsCountriesData(
            array_map(static fn (SmsCountryRow $row): SmsCountryData => new SmsCountryData($row->countryCode, $row->name->in($locale), $row->callingCode, $row->provider), $list->countries),
            $list->providers,
        );
    }
}
