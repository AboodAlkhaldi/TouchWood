<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountry;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountryHandler;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\RenamePlace\RenamePlace;
use Modules\Platform\Application\Command\RenamePlace\RenamePlaceHandler;
use Modules\Platform\Application\Command\RestoreAddressCountry\RestoreAddressCountry;
use Modules\Platform\Application\Command\RestoreAddressCountry\RestoreAddressCountryHandler;
use Modules\Platform\Application\Command\RestorePlace\RestorePlace;
use Modules\Platform\Application\Command\RestorePlace\RestorePlaceHandler;
use Modules\Platform\Application\Command\RetireAddressCountry\RetireAddressCountry;
use Modules\Platform\Application\Command\RetireAddressCountry\RetireAddressCountryHandler;
use Modules\Platform\Application\Command\RetirePlace\RetirePlace;
use Modules\Platform\Application\Command\RetirePlace\RetirePlaceHandler;
use Modules\Platform\Application\Query\ListAddressCountries\ListAddressCountriesHandler;
use Modules\Platform\Application\Query\ListPlaces\ListPlacesHandler;
use Modules\Platform\Application\Query\PlaceReader;
use Modules\Platform\Domain\Exception\PlaceNotFound;
use Modules\Platform\Presentation\Http\Resource\PlacePages;
use Shared\Domain\Error\DomainError;

/**
 * E8 - the countries a customer may choose in an address, and each country's regions, cities and
 * districts (platform.md §4.3; stage 7 step 1): a page for the countries, then a page per level with
 * the panel's trail (the owner, 2026-10-29, Q4).
 *
 * This controller checks nothing. Both permissions are reserved, so only a Super Admin is offered a
 * button; each handler asks again, and a refusal comes back to the page in its own words.
 */
final readonly class PlacesController
{
    /** @var list<string> The add and rename dialog's fields, kept when it is refused. */
    private const array NAME_FIELDS = ['name_ar', 'name_en'];

    /** @var list<string> */
    private const array WORDS = ['platform::admin_places', 'platform::errors', 'access::errors', 'admin'];

    public function __construct(
        private Page $page,
        private PlacePages $pages,
        private PlaceReader $places,
    ) {}

    /** The countries page. */
    public function index(ListAddressCountriesHandler $handler): Response
    {
        return $this->page->render('Platform/Admin/Places/Index', $this->pages->countries($handler)->toArray(), self::WORDS);
    }

    /** A country's regions. An unknown country is the panel's not-found page: ListPlaces refuses it. */
    public function regions(ListPlacesHandler $handler, string $country): Response
    {
        return $this->level($handler, $country, null, null);
    }

    /** A region's cities: a region of another country is not found. */
    public function cities(ListPlacesHandler $handler, string $country, string $region): Response
    {
        return $this->level($handler, $country, $region, null);
    }

    /** A city's districts: a city outside the region named is not found. */
    public function districts(ListPlacesHandler $handler, string $country, string $region, string $city): Response
    {
        return $this->level($handler, $country, $region, $city);
    }

    public function addCountry(Request $request, AddAddressCountryHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new AddAddressCountry(strtoupper(trim($request->string('country')->toString())))), 'country_added');
    }

    public function retireCountry(Request $request, string $country, RetireAddressCountryHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RetireAddressCountry($country)), 'country_retired');
    }

    public function restoreCountry(Request $request, string $country, RestoreAddressCountryHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RestoreAddressCountry($country)), 'country_restored');
    }

    /** Add Region, Add City, Add District: the kind and the place above it come from the page. */
    public function addPlace(Request $request, string $country, AddPlaceHandler $handler): RedirectResponse
    {
        $parent = trim($request->string('parent')->toString());

        return self::act($request, fn () => $handler->handle(new AddPlace(
            $country,
            $request->string('kind')->toString(),
            $parent === '' ? null : $parent,
            $request->string('name_ar')->toString(),
            $request->string('name_en')->toString(),
        )), 'place_added', self::NAME_FIELDS);
    }

    public function renamePlace(Request $request, string $country, string $place, RenamePlaceHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($request, $country, $place, $handler): void {
            $this->inCountry($country, $place);
            $handler->handle(new RenamePlace($place, $request->string('name_ar')->toString(), $request->string('name_en')->toString()));
        }, 'place_renamed', self::NAME_FIELDS);
    }

    public function retirePlace(Request $request, string $country, string $place, RetirePlaceHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($country, $place, $handler): void {
            $this->inCountry($country, $place);
            $handler->handle(new RetirePlace($place));
        }, 'place_retired');
    }

    public function restorePlace(Request $request, string $country, string $place, RestorePlaceHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($country, $place, $handler): void {
            $this->inCountry($country, $place);
            $handler->handle(new RestorePlace($place));
        }, 'place_restored');
    }

    /**
     * The address names the country as well as the place: a place of another country is not found,
     * as the page that shows it would not be (the review of this job).
     *
     * @throws PlaceNotFound
     */
    private function inCountry(string $country, string $place): void
    {
        $found = $this->places->places([strtolower(trim($place))])[strtolower(trim($place))] ?? null;

        if ($found === null || $found->countryCode !== strtoupper(trim($country))) {
            throw new PlaceNotFound($place);
        }
    }

    private function level(ListPlacesHandler $handler, string $country, ?string $region, ?string $city): Response
    {
        return $this->page->render('Platform/Admin/Places/Level', $this->pages->level($handler, $country, $region, $city)->toArray(), self::WORDS);
    }

    /**
     * The change made, then back to the page it was sent from with its toast; refused, back with the
     * refusal and what was typed.
     *
     * @param  list<string>  $keep
     */
    private static function act(Request $request, Closure $work, string $done, array $keep = []): RedirectResponse
    {
        try {
            $work();
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, $keep);
        }

        return back()->with('status', __("platform::admin_places.toast.{$done}"));
    }
}
