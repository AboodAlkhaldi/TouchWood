<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\B2B\Application\B2BPermissions;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Preparing a store before it opens (platform.md §1.6, §9.10; access.md amendments 58(a), 64; owner,
| 2026-10-03 and 2026-10-06).
|
| A Super Admin works inside an off store - its settings, its address form, its company lists - so it
| is ready when it is switched on, without customers ever seeing it half done. Each screen's own store
| filter offers it to them, marked Off. Anyone else - a staff member who covers it, an admin who
| covers every store - is never offered it, and is refused it when asking for it by its code. These go
| through the panel the way a person does, because each screen and the server behind it must agree.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function prepareOffStoreSignIn(string $staffId): AdminBrowser
{
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'en']);
    $browser = new AdminBrowser('10.11.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** Egypt switched off, its id read first: an off store's code finds no store in the fixtures. */
function prepareOffStoreEgyptOff(): string
{
    $egypt = Fx::storeId('eg');
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

    return $egypt;
}

/** A Super Admin signed in, with Egypt off. */
function prepareOffStoreSuperAdmin(): AdminBrowser
{
    prepareOffStoreEgyptOff();

    return prepareOffStoreSignIn(Fx::staff(superAdmin: true));
}

describe('a Super Admin preparing an off store', function () {
    it('is offered it, marked off, on Home, by View Store and in a store screen\'s filter', function () {
        $browser = prepareOffStoreSuperAdmin();

        $browser->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('offersAllStores', true)
                ->where('storeCode', null)
                ->where('stores.1.code', 'eg')
                ->where('stores.1.isActive', false)
                ->where('viewStores.1.code', 'eg')
                ->where('viewStores.1.isActive', false)
            );

        $browser->get('/admin/settings?store=eg')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('storeCode', 'eg')
                ->where('storeName', 'Egypt')
                ->where('storeTimezone', 'Africa/Cairo')
                ->has('stores', 3)
                ->where('stores.1.isActive', false)
            );
    });

    it('sets its settings', function () {
        $browser = prepareOffStoreSuperAdmin();
        $key = CustomerSecuritySettings::LOCKOUT_MINUTES;

        $browser->post("/admin/settings/{$key}", ['value' => '25', 'store' => 'eg'])->assertRedirect();

        $row = DB::table('platform.settings')->where('key', $key)->first()
            ?? throw new RuntimeException('The setting was not stored.');

        expect($row->store_id)->toBe(DB::table('platform.stores')->where('code', 'eg')->value('id'))
            ->and(json_decode((string) $row->value, true))->toBe(25);
    });

    it('sets its address form, finding it by its code', function () {
        $browser = prepareOffStoreSuperAdmin();
        $egypt = (string) DB::table('platform.stores')->where('code', 'eg')->value('id');

        $browser->get('/admin/address-formats?store=eg')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('storeId', $egypt)
                ->where('stores.1.code', 'eg')
                ->where('stores.1.isActive', false)
            );

        $browser->post("/admin/address-formats/{$egypt}", [
            'fields' => [['key' => 'city', 'label_ar' => 'المدينة', 'label_en' => 'City', 'required' => true, 'max_length' => 100]],
            'display_template' => '{city}',
        ])->assertRedirect('/admin/address-formats?store=eg');

        expect(DB::table('access.store_address_formats')->where('store_id', $egypt)->value('display_template'))->toBe('{city}');
    });

    it('adds to its company types', function () {
        $browser = prepareOffStoreSuperAdmin();

        $browser->get('/admin/company-types?store=eg')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('storeName', 'Egypt')->where('storeCode', 'eg'));

        $browser->post('/admin/company-types', ['name_ar' => 'جمعية تعاونية', 'name_en' => 'Cooperative Society', 'position' => '70', 'store' => 'eg'])
            ->assertRedirect();

        expect(DB::table('b2b.company_types')
            ->where('store_id', DB::table('platform.stores')->where('code', 'eg')->value('id'))
            ->where('name_en', 'Cooperative Society')
            ->exists())->toBeTrue();
    });

    it('never lands in it without choosing it, even when it comes first', function () {
        $browser = prepareOffStoreSuperAdmin();

        // No store asked: the first that is on (platform.md §9.10 #3).
        $browser->get('/admin/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('storeCode', 'sa'));
        $browser->get('/admin/company-types')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('storeCode', 'sa'));
    });
});

describe('a staff member who covers an off store', function () {
    it('is never offered it, and is refused it when asking for it by its code', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW, B2BPermissions::COMPANY_TYPE_UPDATE], ['sa', 'eg']);
        prepareOffStoreEgyptOff();
        $browser = prepareOffStoreSignIn($staffId);

        $browser->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('viewStores', [['code' => 'sa', 'name' => 'Saudi Arabia', 'isActive' => true]]));

        $browser->get('/admin/company-types')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('storeCode', 'sa')->has('stores', 1));
        $browser->get('/admin/company-types?store=eg')->assertForbidden();
        $browser->post('/admin/company-types', ['name_ar' => 'جمعية تعاونية', 'name_en' => 'Cooperative Society', 'position' => '70', 'store' => 'eg']);

        expect(DB::table('b2b.company_types')->where('name_en', 'Cooperative Society')->exists())->toBeFalse();
    });

    it('cannot open its address form by its code', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW, AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa', 'eg']);
        prepareOffStoreEgyptOff();
        $browser = prepareOffStoreSignIn($staffId);

        $browser->get('/admin/address-formats?store=eg')->assertNotFound();
    });

    /*
    | The server refuses it too, not only the screens (owner, 2026-10-03; access.md amendment
    | 58(f)): a request sent straight to it, past every screen, changes nothing in an off store.
    */
    it('is refused its address form when it sends one straight to the server', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW, AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa', 'eg']);
        $egypt = prepareOffStoreEgyptOff();
        $before = DB::table('access.store_address_formats')->where('store_id', $egypt)->value('display_template');
        $browser = prepareOffStoreSignIn($staffId);

        $refused = $browser->post("/admin/address-formats/{$egypt}", [
            'fields' => [['key' => 'city', 'label_ar' => 'المدينة', 'label_en' => 'City', 'required' => true, 'max_length' => 100]],
            'display_template' => '{city}',
        ]);

        // The same form, sent for Saudi Arabia, saves: so the refusal is the store's, not the form's.
        $browser->post('/admin/address-formats/'.Fx::storeId('sa'), [
            'fields' => [['key' => 'city', 'label_ar' => 'المدينة', 'label_en' => 'City', 'required' => true, 'max_length' => 100]],
            'display_template' => '{city}',
        ])->assertRedirect('/admin/address-formats?store=sa');

        // A refusal of the whole form, as for a store that does not exist - not a field's, which a
        // form the server would not take would have brought instead.
        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(DB::table('access.store_address_formats')->where('store_id', $egypt)->value('display_template'))->toBe($before);
    });

    it('is refused a change to its company types sent straight to the server, as a type that does not exist', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW, B2BPermissions::COMPANY_TYPE_UPDATE], ['sa', 'eg']);
        $egypt = prepareOffStoreEgyptOff();
        $type = DB::table('b2b.company_types')->where('store_id', $egypt)->orderBy('position')->first()
            ?? throw new RuntimeException('Egypt has no company types.');
        $browser = prepareOffStoreSignIn($staffId);

        $refused = $browser->post("/admin/company-types/{$type->id}/rename", ['name_ar' => 'اسم آخر', 'name_en' => 'Another Name']);

        expect(AdminBrowser::formError($refused))->toBe(trans('b2b::errors.type_not_found.detail', [], 'en'))
            ->and(DB::table('b2b.company_types')->where('id', $type->id)->value('name_en'))->toBe($type->name_en);
    });
});

describe('an admin who covers every store, without being a Super Admin', function () {
    it('is not offered an off store in any list, and is refused it on every screen and save', function () {
        // Every store, and every admin-only action in them - still not a Super Admin.
        $adminId = Fx::staffWith([PlatformPermissions::STORE_VIEW, AccessPermissions::ADDRESS_FORMAT_UPDATE, AccessPermissions::SETTINGS_UPDATE], ['*'], RoleLevel::Admin);
        $egypt = prepareOffStoreEgyptOff();
        $browser = prepareOffStoreSignIn($adminId);
        $key = CustomerSecuritySettings::LOCKOUT_MINUTES;

        $browser->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('viewStores', 2)->where('viewStores.1.code', 'ae'));
        $browser->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('stores', 2)->where('stores.1.code', 'ae'));
        $browser->get('/admin/settings?store=eg')->assertForbidden();
        $browser->get('/admin/address-formats?store=eg')->assertNotFound();

        // Sent straight to the server: UpdateSetting refuses an off store to anyone but a Super Admin
        // (platform.md §9.10 #3), as a store that does not exist.
        $refused = $browser->post("/admin/settings/{$key}", ['value' => '25', 'store' => 'eg']);

        expect(AdminBrowser::formError($refused) ?? AdminBrowser::flashed($refused, 'errors'))->not->toBeNull()
            ->and(DB::table('platform.settings')->where('key', $key)->where('store_id', $egypt)->exists())->toBeFalse();
    });
});
