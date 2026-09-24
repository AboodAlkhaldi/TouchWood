<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The store address format editor (stage 2b, frontend.md §3.7, decided 2026-09-19).
|
| The handler behind it has its own tests in Access step 5; these are about the screen: which
| countries it offers, that it refuses one this person may not change, and that what is saved is
| what the shop then asks a customer for.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A browser signed in as a staff member holding these permissions in these stores. Named for this
 * file: a function declared in a Pest file is global to the whole suite.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function addressFormatStaff(array $permissions, array $stores = ['sa']): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores, RoleLevel::Admin);
    $browser = new AdminBrowser('10.8.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

/**
 * A whole format, as the editor sends it.
 *
 * @param  list<array<string, mixed>>  $fields
 * @return array<string, mixed>
 */
function addressFormatForm(array $fields, string $template): array
{
    return ['fields' => $fields, 'display_template' => $template];
}

describe('the editor', function () {
    it('offers only the countries this person may change, with the one they asked for open', function () {
        $browser = addressFormatStaff([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']);

        $browser->get('/admin/address-formats')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Access/Admin/AddressFormats/Index')
                ->has('stores', 1)
                ->where('stores.0.code', 'sa')
                ->where('stores.0.hasFormat', true)
                ->where('storeId', Fx::storeId('sa'))
                ->where('exists', true)
                // The starting format every store is given when the schema is migrated
                // (access.md amendment 41), labelled in both languages.
                ->where('fields.0.key', 'administrative_area')
                ->where('fields.0.labelEn', 'Region')
                ->where('fields.0.labelAr', 'المنطقة')
                ->where('fields.0.required', true)
                // The domain's own limits, so the screen says the rule that is enforced.
                ->where('maxFields', 60)
                ->where('maxLength', 1000)
            );
    });

    it('offers all three to somebody who may change all three', function () {
        $browser = addressFormatStaff([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa', 'eg', 'ae']);

        $browser->get('/admin/address-formats')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('stores', 3));
    });

    it('refuses a country this person may not change', function () {
        $browser = addressFormatStaff([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']);

        // Never quietly swapped for one they may: the read refuses it.
        $browser->get('/admin/address-formats?store=eg')->assertForbidden();
    });

    it('answers 404 for a country that does not exist at all', function () {
        $browser = addressFormatStaff([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']);

        $browser->get('/admin/address-formats?store=zz')->assertNotFound();
    });

    it('says the country has no form yet, rather than showing an empty one as if it had', function () {
        DB::table('access.store_address_formats')->where('store_id', Fx::storeId('sa'))->delete();

        $browser = addressFormatStaff([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']);

        $browser->get('/admin/address-formats')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('exists', false)
                ->where('fields', [])
                ->where('displayTemplate', '')
                ->where('stores.0.hasFormat', false)
            );
    });
});

describe('saving a form', function () {
    it('saves the fields in the order they were sent, and the shop then asks for them', function () {
        $storeId = Fx::storeId('sa');
        $browser = addressFormatStaff([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']);

        $browser->post("/admin/address-formats/{$storeId}", addressFormatForm([
            ['key' => 'city', 'label_ar' => 'المدينة', 'label_en' => 'City', 'required' => true, 'max_length' => 100],
            ['key' => 'street', 'label_ar' => 'الشارع', 'label_en' => 'Street', 'required' => true, 'max_length' => 200],
            ['key' => 'postal_code', 'label_ar' => 'الرمز البريدي', 'label_en' => 'Postal code', 'required' => false, 'max_length' => 20],
        ], "{street}\n{city} {postal_code}"))
            // Back to the same country, named the way the page names it - a redirect carrying an
            // id would answer 404, because the editor asks for a store by its code.
            ->assertRedirect('/admin/address-formats?store=sa');

        // Nobody typed a position: the order is the order of the list, numbered by the server.
        $browser->get('/admin/address-formats?store=sa')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('fields', 3)
                ->where('fields.0.key', 'city')
                ->where('fields.1.key', 'street')
                ->where('fields.2.key', 'postal_code')
                ->where('fields.2.required', false)
                ->where('displayTemplate', "{street}\n{city} {postal_code}")
            );

        // And the shop asks a customer for exactly those, in that order.
        $customerId = Fx::customer('sara@example.test', 'sa');
        $shopper = new AdminBrowser;
        $shopper->post('/sa/en/account/sign-in', [
            'email' => (string) DB::table('access.customers')->where('id', $customerId)->value('email'),
            'password' => Fx::CUSTOMER_PASSWORD,
        ])->assertRedirect('/sa/en');

        $shopper->get('/sa/en/account?tab=addresses')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('addresses.0.fields', 3)
                ->where('addresses.0.fields.0.key', 'city')
                ->where('addresses.0.fields.0.label', 'City')
            );
    });

    it('refuses a template naming a field the form does not have', function () {
        $storeId = Fx::storeId('sa');
        $browser = addressFormatStaff([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']);

        $refused = $browser->post("/admin/address-formats/{$storeId}", addressFormatForm([
            ['key' => 'city', 'label_ar' => 'المدينة', 'label_en' => 'City', 'required' => true, 'max_length' => 100],
        ], '{city}, {nowhere}'));

        // A placeholder nothing can fill would print as a gap on a shipping label.
        expect(AdminBrowser::formError($refused))->not->toBeNull();
    });

    it('refuses a form with no field at all', function () {
        $storeId = Fx::storeId('sa');
        $browser = addressFormatStaff([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']);

        $refused = $browser->post("/admin/address-formats/{$storeId}", addressFormatForm([], ''));
        $errors = AdminBrowser::flashed($refused, 'errors');
        $errors = is_array($errors) ? ($errors['default']['messages'] ?? []) : [];

        expect($errors)->toHaveKey('fields');
    });

    it('refuses somebody who may not change that country', function () {
        $other = Fx::storeId('eg');
        $browser = addressFormatStaff([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']);

        $before = DB::table('access.store_address_formats')->where('store_id', $other)->value('fields');

        $browser->post("/admin/address-formats/{$other}", addressFormatForm([
            ['key' => 'city', 'label_ar' => 'المدينة', 'label_en' => 'City', 'required' => true, 'max_length' => 100],
        ], '{city}'));

        expect(DB::table('access.store_address_formats')->where('store_id', $other)->value('fields'))->toBe($before);
    });
});
