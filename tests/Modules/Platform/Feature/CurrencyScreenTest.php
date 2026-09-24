<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/*
| Stage 2b, step 3 - the currencies screen over HTTP (frontend.md §3.5, E3).
|
| Every helper here is named after this file's subject. A function declared in a Pest file is global
| to the whole suite, so two files sharing a name stop every run (project conventions).
*/

/**
 * A browser signed in completely as this staff member.
 */
function currencyScreenSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

describe('the currencies screen', function () {
    it('opens for a Super Admin, with the decimal places settled where a store already charges', function () {
        $browser = currencyScreenSignIn(Fx::staff(superAdmin: true));

        $browser->get('/admin/currencies')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->component('Platform/Admin/Currencies/Index')
                ->has('currencies', 3)
                ->where('currencies.0.code', 'AED')
                ->where('currencies.0.exponentLocked', true)
                // From the domain's own limit, so the screen cannot offer one it would refuse.
                ->where('exponents', [0, 1, 2, 3, 4, 5, 6])
            );
    });

    it('refuses the screen to an admin, because the permission is reserved', function () {
        $browser = currencyScreenSignIn(Fx::staffWith(
            [PlatformPermissions::STORE_UPDATE],
            ['sa'],
            RoleLevel::Admin,
        ));

        // Navigation, so it is the error page rather than a message (owner, 2026-09-24).
        $browser->get('/admin/currencies')->assertForbidden();
    });

    it('adds a currency', function () {
        $browser = currencyScreenSignIn(Fx::staff(superAdmin: true));

        $browser->post('/admin/currencies', [
            'code' => 'KWD',
            'exponent' => '3',
            'name_ar' => 'دينار كويتي',
            'name_en' => 'Kuwaiti dinar',
            'abbreviation_ar' => 'د.ك',
            'abbreviation_en' => 'KWD',
            'sign' => '',
        ])->assertRedirect();

        // Absent means this test's own premise is wrong, so it says so rather than failing later
        // on a property of null.
        $row = DB::table('platform.currencies')->where('code', 'KWD')->first()
            ?? throw new RuntimeException('The currency was not added.');

        expect($row->exponent)->toBe(3)
            // An empty sign field is an instruction, not a missing answer: prices fall back to the
            // abbreviation.
            ->and($row->sign)->toBeNull();
    });

    it('saves a name without touching the decimal places of a currency in use', function () {
        $browser = currencyScreenSignIn(Fx::staff(superAdmin: true));

        // What the screen sends for a settled currency: everything but the exponent.
        $browser->post('/admin/currencies/SAR', [
            'name_ar' => 'ريال سعودي',
            'name_en' => 'Saudi riyal',
            'abbreviation_ar' => 'ر.س',
            'abbreviation_en' => 'SAR',
            'sign' => '⃁',
        ])->assertRedirect();

        $row = DB::table('platform.currencies')->where('code', 'SAR')->first()
            ?? throw new RuntimeException('The riyal is missing from the seeded data.');

        expect($row->sign)->toBe('⃁')
            ->and($row->exponent)->toBe(2);
    });

    it('refuses a change to the decimal places of a currency a store charges in', function () {
        $browser = currencyScreenSignIn(Fx::staff(superAdmin: true));

        // Not something the screen offers - the field is shown as settled - so this is somebody
        // posting it anyway. Refused, and answered in the page, because it is an action.
        $response = $browser->post('/admin/currencies/SAR', [
            'name_ar' => 'ريال سعودي',
            'name_en' => 'Saudi riyal',
            'abbreviation_ar' => 'ر.س',
            'abbreviation_en' => 'SAR',
            'sign' => '',
            'exponent' => '0',
        ]);

        $response->assertRedirect();
        expect(DB::table('platform.currencies')->where('code', 'SAR')->value('exponent'))->toBe(2);
    });
});
