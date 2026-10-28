<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\FormErrors;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return self::act($request, fn () => $handler->handle(new RenamePlace($place, $request->string('name_ar')->toString(), $request->string('name_en')->toString())), 'place_renamed', self::NAME_FIELDS);
    }

    public function retirePlace(Request $request, string $country, string $place, RetirePlaceHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RetirePlace($place)), 'place_retired');
    }

    public function restorePlace(Request $request, string $country, string $place, RestorePlaceHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RestorePlace($place)), 'place_restored');
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
