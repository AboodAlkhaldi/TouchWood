<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Shipping\Application\Command\ChangeCarrier\ChangeCarrier;
use Modules\Shipping\Application\Command\ChangeCarrier\ChangeCarrierHandler;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrier;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrierHandler;
use Modules\Shipping\Application\Command\DeleteCarrier\DeleteCarrier;
use Modules\Shipping\Application\Command\DeleteCarrier\DeleteCarrierHandler;
use Modules\Shipping\Application\Command\OrderCarriers\OrderCarriers;
use Modules\Shipping\Application\Command\OrderCarriers\OrderCarriersHandler;
use Modules\Shipping\Application\Command\SwitchCarrier\SwitchCarrier;
use Modules\Shipping\Application\Command\SwitchCarrier\SwitchCarrierHandler;
use Modules\Shipping\Application\Command\UploadCarrierLogo\UploadCarrierLogo;
use Modules\Shipping\Application\Command\UploadCarrierLogo\UploadCarrierLogoHandler;
use Modules\Shipping\Application\Query\ListCarriers\ListCarriersHandler;
use Modules\Shipping\Application\Query\ViewCarrier\ViewCarrierHandler;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNameTaken;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Presentation\Http\Resource\CarrierPages;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/*
| The carriers screens (shipping.md §4.1 part 1; the owner, 2026-10-29, (a) in all sixteen): C1 the list
| by country, C2 the add and edit dialog, C3 a carrier's page. Every command names its store (`store`,
| its code) and asks step 3's handlers, which authorize again (handoff §19).
*/
final readonly class CarriersController
{
    /**
     * Catalog's shared parts' words, only the groups they use: the sortable list's drag handle and Move to
     * Top / Bottom, and the logo box (ImageField).
     *
     * @var list<string>
     */
    private const array WORDS = ['shipping::admin_carriers', 'shipping::errors', 'admin', 'catalog::admin.drag', 'catalog::admin.order', 'catalog::admin.image'];

    /** The dialog's own boxes, where a refusal naming one is said. */
    private const array FIELDS = ['name_ar', 'name_en', 'logo_media_id', 'phone', 'website', 'tracking_pattern'];

    public function __construct(
        private Page $page,
    ) {}

    /** C1. */
    public function index(Request $request, CarrierPages $pages, ListCarriersHandler $carriers): Response
    {
        return $this->page->render('Shipping/Admin/Carriers/Index', $pages->list(self::query($request, 'store'), $carriers)->toArray(), self::WORDS);
    }

    /** C3. */
    public function show(Request $request, string $carrier, CarrierPages $pages, ViewCarrierHandler $view): Response
    {
        return $this->page->render('Shipping/Admin/Carriers/Show', $pages->carrier(self::query($request, 'store'), $carrier, $view)->toArray(), self::WORDS);
    }

    /** Add Carrier, in the section's country (Q3). */
    public function store(Request $request, StoreChoices $choices, CreateCarrierHandler $handler, UploadCarrierLogoHandler $upload): RedirectResponse
    {
        return self::logoNotArrived($request) ?? self::act($request, function () use ($request, $choices, $handler, $upload): void {
            $store = self::storeOf($request, $choices);
            $handler->handle(new CreateCarrier(
                $store->id,
                strtoupper(self::text($request, 'country') ?? ''),
                self::text($request, 'name_ar') ?? '',
                self::text($request, 'name_en') ?? '',
                self::logo($request, $store, $upload),
                self::text($request, 'phone'),
                self::text($request, 'website'),
                self::text($request, 'tracking_pattern'),
                $request->boolean('active', true),
            ));
        }, 'shipping::admin_carriers.toast.added');
    }

    /** Edit Carrier: the same boxes; the country stays (§1.3). */
    public function update(Request $request, string $carrier, StoreChoices $choices, ChangeCarrierHandler $handler, UploadCarrierLogoHandler $upload): RedirectResponse
    {
        return self::logoNotArrived($request) ?? self::act($request, function () use ($request, $carrier, $choices, $handler, $upload): void {
            $store = self::storeOf($request, $choices);
            $handler->handle(new ChangeCarrier(
                $store->id,
                strtolower($carrier),
                self::text($request, 'name_ar') ?? '',
                self::text($request, 'name_en') ?? '',
                self::logo($request, $store, $upload),
                self::text($request, 'phone'),
                self::text($request, 'website'),
                self::text($request, 'tracking_pattern'),
            ));
        }, 'shipping::admin_carriers.toast.saved');
    }

    /** The switch: off asks first on the screen, on asks nothing (Q5). */
    public function switch(Request $request, string $carrier, StoreChoices $choices, SwitchCarrierHandler $handler): RedirectResponse
    {
        $on = $request->boolean('active');

        return self::act(
            $request,
            fn () => $handler->handle(new SwitchCarrier(self::storeOf($request, $choices)->id, strtolower($carrier), $on)),
            $on ? 'shipping::admin_carriers.toast.on' : 'shipping::admin_carriers.toast.off',
        );
    }

    /** Dragged, or moved to the top or the bottom: one country's whole order (Q7; §9.4 #5). */
    public function order(Request $request, StoreChoices $choices, OrderCarriersHandler $handler): RedirectResponse
    {
        $ids = $request->input('ids');
        $ids = is_array($ids) ? array_values(array_map(static fn (mixed $id): string => strtolower(is_string($id) ? $id : ''), $ids)) : [];

        return self::act(
            $request,
            fn () => $handler->handle(new OrderCarriers(self::storeOf($request, $choices)->id, strtoupper(self::text($request, 'country') ?? ''), $ids)),
            'shipping::admin_carriers.toast.ordered',
        );
    }

    /** Delete, only while off (Q6): the page goes back to the list when it was the carrier's own. */
    public function destroy(Request $request, string $carrier, StoreChoices $choices, DeleteCarrierHandler $handler): RedirectResponse
    {
        try {
            $store = self::storeOf($request, $choices);
            $handler->handle(new DeleteCarrier($store->id, strtolower($carrier)));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        $done = __('shipping::admin_carriers.toast.deleted');

        return $request->boolean('from_page')
            ? redirect()->route('shipping.admin.carriers', ['store' => $store->code])->with('status', $done)
            : back()->with('status', $done);
    }

    /**
     * The store the form names, where the reader may manage carriers.
     *
     * @throws Unauthorized
     */
    private static function storeOf(Request $request, StoreChoices $choices): StoreDto
    {
        // Named, never guessed: without a store, `chosen()` would take the first one that is on.
        $code = self::text($request, 'store') ?? throw new Unauthorized(ShippingPermissions::CARRIERS_MANAGE);

        return $choices->chosen($code, ShippingPermissions::CARRIERS_MANAGE) ?? throw new Unauthorized(ShippingPermissions::CARRIERS_MANAGE);
    }

    /**
     * The logo the form saves: a new file uploaded first (UploadCarrierLogo); "remove" clears it;
     * otherwise the one it had.
     */
    private static function logo(Request $request, StoreDto $store, UploadCarrierLogoHandler $upload): ?string
    {
        $file = $request->file('logo');

        if ($file instanceof UploadedFile) {
            return $upload->handle(new UploadCarrierLogo($store->id, (string) $file->getRealPath(), $file->getClientOriginalName()));
        }

        return $request->boolean('remove_logo') ? null : self::text($request, 'logo_media_id');
    }

    /**
     * A logo that did not arrive whole - most often one larger than the server accepts, which PHP
     * drops before any handler sees it - refused beside its box, never a 500 (as Catalog's forms do).
     */
    private static function logoNotArrived(Request $request): ?RedirectResponse
    {
        $sent = $request->file('logo');

        if ($sent === null || ($sent instanceof UploadedFile && $sent->isValid() && $sent->getRealPath() !== false)) {
            return null;
        }

        return back()->withInput()->withErrors(['logo_media_id' => __('shipping::admin_carriers.logo_not_arrived')]);
    }

    /**
     * Does the work and answers with the toast, or with the refusal - beside its own box when it names
     * one of the dialog's (a name taken, beside the name in its language - §9.4 #4); otherwise at the top.
     *
     * @param  Closure(): mixed  $work
     */
    private static function act(Request $request, Closure $work, string $done): RedirectResponse
    {
        try {
            $work();
        } catch (InvalidShippingInput $error) {
            if (in_array($error->field, self::FIELDS, true) && ! $request->expectsJson()) {
                return back()->withInput()->withErrors([$error->field => FormErrors::message($error)]);
            }

            return FormErrors::back($request, $error);
        } catch (CarrierNameTaken $error) {
            $language = $error->context()['language'] === 'ar' ? 'ar' : 'en';

            return back()->withInput()->withErrors(["name_{$language}" => FormErrors::message($error)]);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __($done));
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
