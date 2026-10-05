<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Access\Application\Command\LeaveStaffView\LeaveStaffView;
use Modules\Access\Application\Command\LeaveStaffView\LeaveStaffViewHandler;
use Modules\Access\Application\Command\OpenStaffView\OpenStaffView;
use Modules\Access\Application\Command\OpenStaffView\OpenStaffViewHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff view (spec §1.11; frontend.md §2.2, §2.3): View Store in the panel's header, and Leave
 * Staff View in the shop.
 */
final readonly class StaffViewController
{
    /**
     * The shop's home page of the store being worked in, in the panel's language - every store
     * offers both (platform.md §1.6). A full visit, not an Inertia one: it leaves the panel's frame.
     */
    public function open(Request $request, OpenStaffViewHandler $handler, PlatformApi $platform): Response|RedirectResponse
    {
        try {
            $storeId = $handler->handle(new OpenStaffView);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        $store = $platform->store(StoreId::fromString($storeId));

        // Opened just now from the panel's own answer, so the store exists; were it gone, an empty
        // code would make "//en" - an address on another host - so it goes nowhere instead.
        if ($store === null) {
            return back();
        }

        return Inertia::location("/{$store->code}/".app()->getLocale());
    }

    /**
     * Back to the home page of the store it was left from, as whoever the shop's session holds - or
     * to the country page when that store is off, which a visitor cannot open. The store is looked
     * up among the stores that are on, so the code sent can lead nowhere else.
     */
    public function leave(Request $request, LeaveStaffViewHandler $handler, PlatformApi $platform): RedirectResponse
    {
        try {
            $handler->handle(new LeaveStaffView);
        } catch (Unauthorized) {
            // Not expected - the top level identifies nobody, so this is a guest's request - and
            // a refusal would only mean there is no view here to leave.
        }

        $code = $request->string('store')->toString();
        $store = $code === '' ? null : $platform->storeByCode($code);

        return $store === null
            ? redirect('/')
            : redirect()->route('storefront.home', ['store' => $store->code, 'locale' => app()->getLocale()]);
    }
}
