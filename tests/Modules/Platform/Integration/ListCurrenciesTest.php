<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Query\ListCurrencies\CurrencySummary;
use Modules\Platform\Application\Query\ListCurrencies\ListCurrencies;
use Modules\Platform\Application\Query\ListCurrencies\ListCurrenciesHandler;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * One currency from the screen's list. Absent means this test's own premise is wrong, so it says
 * so rather than failing later on a property of null.
 */
function listedCurrency(string $code): CurrencySummary
{
    $currencies = app(ListCurrenciesHandler::class)->handle(new ListCurrencies);

    return collect($currencies)->firstWhere('code', $code)
        ?? throw new RuntimeException("The currencies list has no {$code}.");
}

/**
 * A currency no store charges in, made as the system.
 */
function unusedCurrency(string $code): void
{
    Fx::asSystem(function () use ($code): void {
        app(CreateCurrencyHandler::class)->handle(
            new CreateCurrency($code, 2, 'عملة', 'Currency', 'ع', $code, null),
        );
    });
}

/**
 * Stage 2b, step 3. What the currencies screen is handed (frontend.md §3.5, E3).
 *
 * Currencies belong to no store, and both permissions are reserved, so this is a Super Admin's
 * screen and nobody else's. What is tested here is that, and the one thing the screen must get
 * right: whether the decimal places are still open to change.
 */
describe('the currencies a person is shown', function () {
    it('refuses anybody who is not a Super Admin, however much a role gives them', function () {
        // Reserved permissions cannot be put in a role at all (platform.md §3), so the most
        // generous admin in the system still does not reach this screen.
        Fx::actAsAdmin(['sa'], [PlatformPermissions::STORE_UPDATE, PlatformPermissions::SETTINGS_UPDATE]);

        expect(fn () => listedCurrency('SAR'))->toThrow(Unauthorized::class);
    });

    it('shows a Super Admin every currency, by code', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $codes = array_map(
            static fn (CurrencySummary $currency): string => $currency->code,
            app(ListCurrenciesHandler::class)->handle(new ListCurrencies),
        );

        expect($codes)->toBe(['AED', 'EGP', 'SAR']);
    });

    it('settles the decimal places of a currency a store already charges in', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        // Changing it now would reinterpret every amount ever written in it (platform.md §1.2).
        $riyal = listedCurrency('SAR');

        expect($riyal->exponentLocked)->toBeTrue()
            ->and($riyal->storeCount)->toBe(1);
    });

    it('leaves the decimal places open on a currency no store uses yet', function () {
        unusedCurrency('KWD');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $dinar = listedCurrency('KWD');

        expect($dinar->exponentLocked)->toBeFalse()
            ->and($dinar->storeCount)->toBe(0);
    });
});
