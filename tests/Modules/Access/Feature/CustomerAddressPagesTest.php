<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| A customer's addresses, and closing their account (stage 2b step 4, frontend.md §3.6, F9 and F10).
|
| The handlers are tested in Access's own step 5 suites; these are about the pages: what the book
| shows, that a country we do not deliver to says so rather than offering a form, and that closing
| an account ends every session of it at once.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A customer, and a browser signed in as them. Named for this file: a function declared in a Pest
 * file is global to the whole suite.
 *
 * @return array{0: string, 1: AdminBrowser}
 */
function addressPageCustomer(): array
{
    $customerId = Fx::customer();
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');

    $browser = new AdminBrowser;
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])
        ->assertRedirect('/sa/en');

    return [$customerId, $browser];
}

/**
 * One address, as the form sends it.
 *
 * @param  array<string, mixed>  $extra  anything the caller wants different, including the values
 * @return array<string, mixed>
 */
function addressForm(string $storeId, array $extra = []): array
{
    return [
        'store_id' => $storeId,
        'label' => 'Home',
        'recipient_name' => 'Sara Ali',
        'phone' => '+966512345678',
        'fields' => [
            'administrative_area' => 'Riyadh Region',
            'city' => 'Riyadh',
            'district' => 'Al Olaya',
            'street' => 'King Fahd Road',
            'building' => '7',
        ],
        ...$extra,
    ];
}

describe('the address book (F9)', function () {
    it('lists every country we sell in, with the fields that country asks for', function () {
        [, $browser] = addressPageCustomer();

        $browser->get('/sa/en/account?tab=addresses')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tab', 'addresses')
                // Every store, not only the one they registered in: they may shop in any of them.
                ->has('addresses', 3)
                ->where('addresses.0.storeCode', 'sa')
                ->where('addresses.0.hasFormat', true)
                ->where('addresses.0.addresses', [])
                ->where('addresses.0.full', false)
                // The store's own fields, in its own order, labelled in the page's language.
                ->where('addresses.0.fields.0.key', 'administrative_area')
                ->where('addresses.0.fields.0.label', 'Region')
                ->where('addresses.0.fields.0.required', true)
            );
    });

    it('labels the fields in the language the page is read in', function () {
        [, $browser] = addressPageCustomer();

        $browser->get('/sa/ar/account?tab=addresses')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('addresses.0.fields.0.label', 'المنطقة'));
    });

    it('saves one, makes it the default, and shows it in that store\'s own layout', function () {
        [$customerId, $browser] = addressPageCustomer();
        $storeId = Fx::storeId('sa');

        $browser->post('/sa/en/account/addresses', addressForm($storeId))
            ->assertRedirect('/sa/en/account?tab=addresses');

        $browser->get('/sa/en/account?tab=addresses')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('addresses.0.addresses.0.label', 'Home')
                ->where('addresses.0.addresses.0.recipientName', 'Sara Ali')
                // The first address in a store becomes its default on its own (amendment 41).
                ->where('addresses.0.addresses.0.isDefault', true)
                ->where('addresses.0.addresses.0.isComplete', true)
                // What a courier is given: the store's template, not this screen's arrangement.
                ->where('addresses.0.addresses.0.formatted', fn (string $formatted) => str_contains($formatted, 'Riyadh'))
            );

        expect(DB::table('access.addresses')->where('customer_id', $customerId)->count())->toBe(1);
    });

    it('moves the default to another address when asked', function () {
        [, $browser] = addressPageCustomer();
        $storeId = Fx::storeId('sa');

        $browser->post('/sa/en/account/addresses', addressForm($storeId));
        $browser->post('/sa/en/account/addresses', addressForm($storeId, ['label' => 'Work']));

        $work = (string) DB::table('access.addresses')->where('label', 'Work')->value('id');

        $browser->post("/sa/en/account/addresses/{$work}/default")
            ->assertRedirect('/sa/en/account?tab=addresses');

        expect(DB::table('access.addresses')->where('id', $work)->value('is_default'))->toBeTrue();
    });

    it('removes one, and leaves the store with a default all the same', function () {
        [$customerId, $browser] = addressPageCustomer();
        $storeId = Fx::storeId('sa');

        $browser->post('/sa/en/account/addresses', addressForm($storeId));
        $browser->post('/sa/en/account/addresses', addressForm($storeId, ['label' => 'Work']));

        $default = (string) DB::table('access.addresses')->where('is_default', true)->value('id');

        $browser->post("/sa/en/account/addresses/{$default}/delete")
            ->assertRedirect('/sa/en/account?tab=addresses');

        // A store that has any address always has one marked as usual: the flag moves rather than
        // being left nowhere (amendment 41).
        expect(DB::table('access.addresses')->where('customer_id', $customerId)->count())->toBe(1)
            ->and(DB::table('access.addresses')->where('customer_id', $customerId)->value('is_default'))->toBeTrue();
    });

    it('says we do not deliver to a country that has no address shape yet', function () {
        [, $browser] = addressPageCustomer();

        // A store staff have not given a format to. Nothing may be saved there, and the page says
        // so rather than offering a form that would be refused (access.md §1.9).
        DB::table('access.store_address_formats')->where('store_id', Fx::storeId('eg'))->delete();

        $browser->get('/sa/en/account?tab=addresses')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('addresses.1.storeCode', 'eg')
                ->where('addresses.1.hasFormat', false)
                ->where('addresses.1.fields', [])
            );
    });

    it('refuses a value whose key that country does not ask for', function () {
        [, $browser] = addressPageCustomer();
        $storeId = Fx::storeId('sa');

        $refused = $browser->post('/sa/en/account/addresses', addressForm($storeId, [
            'fields' => ['administrative_area' => 'Riyadh Region', 'invented_key' => 'nothing can show this'],
        ]));

        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(DB::table('access.addresses')->count())->toBe(0);
    });
});

describe('closing the account (F10)', function () {
    it('refuses a wrong password and changes nothing', function () {
        [$customerId, $browser] = addressPageCustomer();

        $refused = $browser->post('/sa/en/account/close', ['current_password' => 'not the password']);

        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(DB::table('access.customers')->where('id', $customerId)->value('deletion_scheduled_for'))->toBeNull();
    });

    it('locks the account, sets the date, and signs them out everywhere at once', function () {
        [$customerId, $browser] = addressPageCustomer();

        $browser->post('/sa/en/account/close', ['current_password' => Fx::CUSTOMER_PASSWORD])
            ->assertRedirect('/sa/en');

        expect(DB::table('access.customers')->where('id', $customerId)->value('deletion_scheduled_for'))->not->toBeNull();

        // Every session of theirs ended the moment they confirmed (amendment 45), so this browser
        // is a visitor on its very next request - which is why there is no cancel button anywhere
        // in the account: they cannot reach one.
        $browser->get('/sa/en/account')->assertRedirect('/sa/en');

        $browser->get('/sa/en')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shopper', null));
    });

    it('tells them the date, and that signing in before it cancels the whole thing', function () {
        [, $browser] = addressPageCustomer();

        $closed = $browser->post('/sa/en/account/close', ['current_password' => Fx::CUSTOMER_PASSWORD]);
        $status = AdminBrowser::flashed($closed, 'status');

        expect($status)->toBeString()
            ->and($status)->toContain(now()->addDays(14)->format('Y-m-d'));
    });
});
