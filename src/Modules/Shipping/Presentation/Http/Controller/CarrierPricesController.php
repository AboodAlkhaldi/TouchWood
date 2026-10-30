<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Controller;

use App\Http\FormErrors;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Shipping\Application\Command\DeletePriceGroup\DeletePriceGroup;
use Modules\Shipping\Application\Command\DeletePriceGroup\DeletePriceGroupHandler;
use Modules\Shipping\Application\Command\SavePriceGroup\SavePriceGroup;
use Modules\Shipping\Application\Command\SavePriceGroup\SavePriceGroupHandler;
use Modules\Shipping\Application\Command\SetCarrierFreeAbove\SetCarrierFreeAbove;
use Modules\Shipping\Application\Command\SetCarrierFreeAbove\SetCarrierFreeAboveHandler;
use Modules\Shipping\Application\Query\ViewCarrier\ViewCarrierHandler;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Presentation\Http\Resource\CarrierPages;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Text\LatinDigits;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\MoneyException;

/*
| A carrier's prices and free-shipping amount (shipping.md §4.1 part 2; the owner, 2026-10-30, (a) in fifteen
| and R6 (c)): the price dialog's place picker, one level at a time (R3); Save Price and Delete Price, each
| sending the version of the prices the page read (R10); the carrier's own free-above amount (R11). Every
| post names its store (`store`, its code) and asks step 4's handlers, which authorize again (handoff §19).
| The store-wide amount is Platform's setting, saved through Platform's own settings route (R12).
*/
final readonly class CarrierPricesController
{
    /** The price dialog's and the free-shipping card's boxes, where a refusal naming one is said. */
    private const array FIELDS = ['amount', 'places', 'free_above'];

    /** R3: the carrier's country's regions, a region's cities (`?region=`), or a city's districts (`?city=`). */
    public function places(Request $request, string $carrier, CarrierPages $pages, ViewCarrierHandler $view): JsonResponse
    {
        return response()->json($pages->pick(self::query($request, 'store'), $carrier, self::query($request, 'region'), self::query($request, 'city'), $view));
    }

    /** Add Price (no `group`) or Edit Price: the amount, the places, and those moved here (R5, R6). */
    public function save(Request $request, string $carrier, StoreChoices $choices, SavePriceGroupHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($request, $carrier, $choices, $handler): void {
            $store = self::storeOf($request, $choices);
            $handler->handle(new SavePriceGroup(
                $store->id,
                strtolower($carrier),
                self::text($request, 'group') === null ? null : strtolower((string) self::text($request, 'group')),
                self::amount($request, 'amount', $store) ?? throw new InvalidShippingInput('amount', 'required'),
                self::ids($request, 'places'),
                self::text($request, 'version') ?? '',
                self::ids($request, 'moves'),
                $request->boolean('all_unpriced'),
            ));
        }, 'shipping::admin_prices.toast.saved');
    }

    /** Delete Price (R8). */
    public function delete(Request $request, string $carrier, string $group, StoreChoices $choices, DeletePriceGroupHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($request, $carrier, $group, $choices, $handler): void {
            $handler->handle(new DeletePriceGroup(self::storeOf($request, $choices)->id, strtolower($carrier), strtolower($group), self::text($request, 'version') ?? ''));
        }, 'shipping::admin_prices.toast.deleted');
    }

    /** The carrier's own free-above amount: Save, or Clear - never free (R11). */
    public function freeAbove(Request $request, string $carrier, StoreChoices $choices, SetCarrierFreeAboveHandler $handler): RedirectResponse
    {
        $cleared = $request->boolean('clear');

        return self::act($request, function () use ($request, $carrier, $choices, $handler, $cleared): void {
            $store = self::storeOf($request, $choices);
            $amount = $cleared ? null : (self::amount($request, 'free_above', $store) ?? throw new InvalidShippingInput('free_above', 'required'));
            $handler->handle(new SetCarrierFreeAbove($store->id, strtolower($carrier), $amount));
        }, $cleared ? 'shipping::admin_prices.toast.free_cleared' : 'shipping::admin_prices.toast.free_saved');
    }

    /**
     * The store the form names, where the reader may manage prices - named, never guessed.
     *
     * @throws Unauthorized
     */
    private static function storeOf(Request $request, StoreChoices $choices): StoreDto
    {
        $code = self::text($request, 'store') ?? throw new Unauthorized(ShippingPermissions::PRICES_MANAGE);

        return $choices->chosen($code, ShippingPermissions::PRICES_MANAGE) ?? throw new Unauthorized(ShippingPermissions::PRICES_MANAGE);
    }

    /**
     * An amount typed in the store's currency and decimals, Latin digits made (R15), in its smallest unit.
     *
     * @throws InvalidShippingInput
     */
    private static function amount(Request $request, string $key, StoreDto $store): ?int
    {
        $typed = self::text($request, $key);

        if ($typed === null) {
            return null;
        }

        try {
            return Money::fromDecimal(LatinDigits::of($typed), $store->currencyCode, $store->currencyExponent)->minorUnits;
        } catch (MoneyException) {
            throw new InvalidShippingInput($key, 'amount');
        }
    }

    /** @return list<string> the ids posted under the key, lowercased; anything else left out */
    private static function ids(Request $request, string $key): array
    {
        $posted = $request->input($key);

        return is_array($posted) ? array_values(array_map(static fn (string $id): string => strtolower(trim($id)), array_filter($posted, 'is_string'))) : [];
    }

    /**
     * Does the work and answers with the toast, or with the refusal - beside its own box when it names one;
     * otherwise at the top, `PricesChanged` included, which the dialog turns into its Reload (R10).
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
        } catch (DomainError $error) {
            $response = FormErrors::back($request, $error);

            // A save or delete that came after another (R10): the dialog offers Reload, not a retry. A new
            // price with an amount the carrier has: the dialog links to that price (§4.1 part 2).
            return match ($error->type()) {
                'shipping.prices_changed' => back()->withErrors(['stale' => FormErrors::message($error)]),
                'shipping.amount_already_used' => $response->withErrors(['form' => FormErrors::message($error), 'existing' => (string) ($error->context()['group'] ?? '')]),
                default => $response,
            };
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
