<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\FormErrors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountry;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountryHandler;
use Modules\Platform\Application\Command\RemoveStoreCountry\RemoveStoreCountry;
use Modules\Platform\Application\Command\RemoveStoreCountry\RemoveStoreCountryHandler;
use Shared\Domain\Error\DomainError;

/**
 * A store's ship-to countries, set from its card on the Stores page (platform.md §4.3, E1 addition;
 * the owner, 2026-10-29, Q2). Super Admins only: the permission is reserved, and each handler asks.
 * Removing is refused while the store has a carrier there (Shipping's guard), in Shipping's words.
 */
final readonly class StoreCountriesController
{
    public function add(Request $request, string $store, AddStoreCountryHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new AddStoreCountry($store, strtoupper(trim($request->string('country')->toString()))));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('platform::admin_stores.ships_to.added'));
    }

    public function remove(Request $request, string $store, string $country, RemoveStoreCountryHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RemoveStoreCountry($store, $country));
        } catch (DomainError $error) {
            // The store still has carriers there (Shipping's guard): the dialog says so and links to them
            // (shipping.md §4.1 part 1, Q14) - named by its type, so Platform needs nothing of Shipping's.
            if ($error->type() === 'shipping.country_has_carriers') {
                return back()->withErrors(['form' => FormErrors::message($error), 'carriers' => strtoupper($country)]);
            }

            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('platform::admin_stores.ships_to.removed'));
    }
}
