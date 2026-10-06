<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Application\Query\StoreDirectory;
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
| Add Store on the stores screen (platform.md §9.7 #3, #4; owner, 2026-10-06): a Super Admin opens a
| store complete, switched off, with a currency no store uses or a new one made in the same step -
| one currency, one store.
*/

function addStoreSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.13.0.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

function addStoreFreeCurrency(string $code = 'KWD'): void
{
    DB::table('platform.currencies')->insert(['code' => $code, 'exponent' => 3, 'name' => json_encode(['ar' => 'دينار كويتي', 'en' => 'Kuwaiti Dinar']), 'abbreviation' => json_encode(['ar' => 'د.ك', 'en' => $code]), 'created_at' => now(), 'updated_at' => now()]);
    Fx::asSystem(fn () => app(StoreDirectory::class)->invalidate());
}

/**
 * @param  array<string, string|bool>  $overrides
 * @return array<string, string|bool>
 */
function addStoreFields(array $overrides = []): array
{
    return [
        'code' => 'kw',
        'name_ar' => 'الكويت',
        'name_en' => 'Kuwait',
        'country' => 'KW',
        'currency' => 'KWD',
        'tax_rate' => '0',
        'timezone' => 'Asia/Kuwait',
        'position' => '40',
        'new_currency' => false,
        ...$overrides,
    ];
}

it('offers a Super Admin Add Store with the currencies no store uses, and nobody else', function () {
    addStoreFreeCurrency();
    // An off store still has its currency: EGP is not offered while Egypt is off.
    DB::table('platform.stores')->where('code', 'eg')->update(['is_active' => false]);
    Fx::asSystem(fn () => app(StoreDirectory::class)->invalidate());

    addStoreSignIn(Fx::staff(superAdmin: true))->get('/admin/stores')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('add.freeCurrencies', 1)
        ->where('add.freeCurrencies.0.code', 'KWD')
        ->where('add.nextPosition', 13)
        // A country with one time zone fills it; one with several leaves it to be picked.
        ->where('add.zones.KW', 'Asia/Kuwait')
        ->missing('add.zones.US')
    );

    addStoreSignIn(Fx::staffWith([PlatformPermissions::STORE_UPDATE, PlatformPermissions::STORE_VIEW], ['*']))
        ->get('/admin/stores')->assertInertia(fn (AssertableInertia $page) => $page->where('add', null));
});

it('opens a store switched off with a currency no store uses, audited', function () {
    addStoreFreeCurrency();
    $browser = addStoreSignIn(Fx::staff(superAdmin: true));

    expect(AdminBrowser::formError($browser->post('/admin/stores', addStoreFields())))->toBeNull();

    $store = DB::table('platform.stores')->where('code', 'kw')->first();
    expect($store?->currency_code)->toBe('KWD')
        ->and((bool) $store?->is_active)->toBeFalse()
        ->and(DB::table('platform.audit_entries')->where('action', 'platform.store.created')->where('subject_id', $store?->id)->count())->toBe(1);
});

it('opens a store with a new currency made in the same step', function () {
    $browser = addStoreSignIn(Fx::staff(superAdmin: true));

    $created = $browser->post('/admin/stores', addStoreFields([
        'currency' => '',
        'new_currency' => true,
        'currency_code' => 'kwd',
        'currency_exponent' => '3',
        'currency_name_ar' => 'دينار كويتي',
        'currency_name_en' => 'Kuwaiti Dinar',
        'currency_abbreviation_ar' => 'د.ك',
        'currency_abbreviation_en' => 'KWD',
    ]));

    expect(AdminBrowser::formError($created))->toBeNull();

    expect(DB::table('platform.stores')->where('code', 'kw')->value('currency_code'))->toBe('KWD')
        ->and((int) DB::table('platform.currencies')->where('code', 'KWD')->value('exponent'))->toBe(3);
});

it('refuses a currency another store uses, and a reader who may not open stores', function () {
    addStoreFreeCurrency();
    $superAdmin = addStoreSignIn(Fx::staff(superAdmin: true));

    $refused = $superAdmin->post('/admin/stores', addStoreFields(['currency' => 'SAR']));
    expect(AdminBrowser::formError($refused))->toContain('SAR');

    $admin = addStoreSignIn(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*'], RoleLevel::Admin));
    $admin->post('/admin/stores', addStoreFields())->assertRedirect();

    expect(DB::table('platform.stores')->where('code', 'kw')->exists())->toBeFalse();
});
