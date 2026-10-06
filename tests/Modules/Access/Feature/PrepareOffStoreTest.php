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
| Preparing a store before it opens (platform.md §1.6, access.md amendment 58(a); owner, 2026-10-03).
|
| A Super Admin works inside an off store - its settings, its address form, its company lists - so it
| is ready when it is switched on, without customers ever seeing it half done. A staff member who
| covers it sees it in the switcher, marked Off, and may not work in it. These go through the panel
| the way a person does, because the switcher, the header's store and each screen must agree.
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
    $browser = new AdminBrowser('10.11.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** Egypt switched off, its id read first: an off store's code finds no store. */
function prepareOffStoreEgyptOff(): string
{
    $egypt = Fx::storeId('eg');
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

    return $egypt;
}

/** A Super Admin signed in and working in Egypt, which is off. */
function prepareOffStoreInEgypt(): AdminBrowser
{
    $egypt = prepareOffStoreEgyptOff();
    $browser = prepareOffStoreSignIn(Fx::staff(superAdmin: true));

    $browser->post('/admin/current-store', ['store' => $egypt])->assertRedirect();

    return $browser;
}

describe('a Super Admin preparing an off store', function () {
    it('works in it, the page it is handed marking it off', function () {
        $browser = prepareOffStoreInEgypt();

        $browser->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('store.current.name', 'Egypt')
                ->where('store.current.isActive', false)
                ->where('store.current.choosable', true)
                ->has('store.available', 3)
                ->where('store.fellBack', false)
            );
    });

    it('sets its settings', function () {
        $browser = prepareOffStoreInEgypt();
        $key = CustomerSecuritySettings::LOCKOUT_MINUTES;

        $browser->post("/admin/settings/{$key}", ['value' => '25'])->assertRedirect();

        $row = DB::table('platform.settings')->where('key', $key)->first()
            ?? throw new RuntimeException('The setting was not stored.');

        expect($row->store_id)->toBe(DB::table('platform.stores')->where('code', 'eg')->value('id'))
            ->and(json_decode((string) $row->value, true))->toBe(25);
    });

    it('sets its address form, finding it by its code', function () {
        $browser = prepareOffStoreInEgypt();
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
        $browser = prepareOffStoreInEgypt();

        $browser->get('/admin/company-types')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('storeName', 'Egypt'));

        $browser->post('/admin/company-types', ['name_ar' => 'جمعية تعاونية', 'name_en' => 'Cooperative Society', 'position' => '70'])
            ->assertRedirect();

        expect(DB::table('b2b.company_types')
            ->where('store_id', DB::table('platform.stores')->where('code', 'eg')->value('id'))
            ->where('name_en', 'Cooperative Society')
            ->exists())->toBeTrue();
    });
});

describe('a staff member who covers an off store', function () {
    it('sees it marked off and not to be chosen, and is refused it', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa', 'eg']);
        $egypt = prepareOffStoreEgyptOff();
        $browser = prepareOffStoreSignIn($staffId);

        $browser->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('store.current.name', 'Saudi Arabia')
                ->has('store.available', 2)
                ->where('store.available.1.name', 'Egypt')
                ->where('store.available.1.isActive', false)
                ->where('store.available.1.choosable', false)
            );

        $refused = $browser->post('/admin/current-store', ['store' => $egypt]);

        expect(DB::table('access.staff_users')->where('id', $staffId)->value('current_store_id'))->not->toBe($egypt)
            ->and(AdminBrowser::flashed($refused, 'errors'))->not->toBeNull();
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
    it('is not offered an off store to work in, and is refused it', function () {
        // Every store, and every admin-only action in them - still not a Super Admin.
        $adminId = Fx::staffWith([PlatformPermissions::STORE_VIEW, AccessPermissions::ADDRESS_FORMAT_UPDATE], ['*'], RoleLevel::Admin);
        $egypt = prepareOffStoreEgyptOff();
        $browser = prepareOffStoreSignIn($adminId);

        $browser->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('store.available.1.name', 'Egypt')
                ->where('store.available.1.choosable', false)
            );

        $browser->post('/admin/current-store', ['store' => $egypt]);
        $browser->get('/admin/address-formats?store=eg')->assertNotFound();

        expect(DB::table('access.staff_users')->where('id', $adminId)->value('current_store_id'))->not->toBe($egypt);
    });
});
