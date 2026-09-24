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
| A customer's own account (stage 2b step 4, frontend.md §3.6, F7 and F8).
|
| The handlers behind it are tested in Access's own suites; this is about the page: that it shows
| the person asking and nobody else, that what cannot be changed is shown as such, and that a form
| comes back to the tab it was sent from.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A customer, and a browser signed in as them.
 *
 * Named for this file: a function declared in a Pest file is global to the whole suite.
 *
 * @return array{0: string, 1: AdminBrowser}
 */
function accountPageCustomer(): array
{
    $customerId = Fx::customer();
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');

    $browser = new AdminBrowser;
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])
        ->assertRedirect('/sa/en');

    return [$customerId, $browser];
}

describe('the page itself', function () {
    it('shows the person asking, and the three things they cannot change', function () {
        [, $browser] = accountPageCustomer();

        $browser->get('/sa/en/account')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Access/Storefront/Account/Account')
                ->where('tab', 'profile')
                ->where('firstName', 'Sara')
                ->where('lastName', 'Ali')
                ->where('email', 'sara@example.test')
                // Never editable, chosen once, and fixed at registration (access.md §1.1).
                ->where('accountType', 'INDIVIDUAL')
                ->where('homeStore', 'Saudi Arabia')
                // Nothing verified yet, so nothing may be ordered yet (§1.2).
                ->where('emailVerified', false)
                ->where('phoneVerified', false)
                ->where('mayOrder', false)
                ->where('phone', null)
                ->where('passwordMinimumLength', 8)
            );
    });

    it('names the home store in the language the page is read in', function () {
        [, $browser] = accountPageCustomer();

        $browser->get('/sa/ar/account')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('homeStore', 'السعودية'));
    });

    it('opens the tab the address asks for, and ignores one that does not exist', function (string $query, string $expected) {
        [, $browser] = accountPageCustomer();

        $browser->get("/sa/en/account{$query}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', $expected));
    })->with([
        'no tab' => ['', 'profile'],
        'the phone tab' => ['?tab=phone', 'phone'],
        'the addresses tab' => ['?tab=addresses', 'addresses'],
        'a tab nobody has' => ['?tab=orders', 'profile'],
    ]);

    it('has nothing to show a visitor', function () {
        (new AdminBrowser)->get('/sa/en/account')->assertRedirect('/sa/en');
    });

    it('carries the words its own frame reads', function () {
        [, $browser] = accountPageCustomer();

        $browser->get('/sa/en/account')->assertInertia(function (AssertableInertia $page) {
            /** @var array<string, string> $words */
            $words = $page->toArray()['props']['translations'];

            expect($words)->toHaveKey('access::account.shop_title')
                ->and($words)->toHaveKey('access::auth.sign_out')
                ->and($words)->toHaveKey('admin.theme.dark');
        });
    });
});

describe('what the page can change', function () {
    it('saves a new name and comes back to the tab it was sent from', function () {
        [$customerId, $browser] = accountPageCustomer();

        $browser->post('/sa/en/account/profile', [
            'first_name' => 'Noura',
            'last_name' => 'Saleh',
            'locale' => 'ar',
        ])->assertRedirect('/sa/en/account?tab=profile');

        $row = DB::table('access.customers')->where('id', $customerId)->first();

        expect($row?->first_name)->toBe('Noura')
            ->and($row?->last_name)->toBe('Saleh')
            // The communication language, which is not the language the shop is read in: this
            // request was made on an English page.
            ->and($row?->locale)->toBe('ar');
    });

    it('refuses a blank name under the field, and changes nothing', function () {
        [$customerId, $browser] = accountPageCustomer();

        // Shape is the form request's to refuse, and it never reaches the handler: the message
        // belongs under the field somebody left empty, not at the top of the form.
        $refused = $browser->post('/sa/en/account/profile', [
            'first_name' => '   ',
            'last_name' => 'Ali',
            'locale' => 'en',
        ]);

        // Read out of the response's own session, as this suite reads every error: the browser
        // keeps its cookies, so the test case's session is not the one the request used.
        $errors = AdminBrowser::flashed($refused, 'errors');
        $errors = is_array($errors) ? ($errors['default']['messages'] ?? []) : [];

        expect($errors)->toHaveKey('first_name')
            ->and($errors)->not->toHaveKey('form')
            ->and(DB::table('access.customers')->where('id', $customerId)->value('first_name'))->toBe('Sara');
    });

    it('says why a number was refused, at the top of the form, without changing anything', function () {
        // The other customer first: registering one signs them in, which takes the session this
        // process is holding - and the browser signed in below would then be a visitor again
        // (found by running it, 2026-09-25).
        $other = Fx::customer('someone.else@example.test');

        DB::table('access.customers')->where('id', $other)->update([
            'phone' => '+966512345678',
            'phone_verified_at' => now(),
        ]);

        [$customerId, $browser] = accountPageCustomer();

        // A number somebody else already uses is refused now, when it is entered, rather than
        // after a code has gone to it (access.md §1.3).
        $refused = $browser->post('/sa/en/account/phone', ['phone' => '+966512345678']);

        expect(AdminBrowser::formError($refused))->toBe((string) __('access::errors.phone_already_in_use.detail'))
            ->and(DB::table('access.customers')->where('id', $customerId)->value('phone'))->toBeNull();
    });

    it('adds a phone in two steps, and only the second one changes it', function () {
        [$customerId, $browser] = accountPageCustomer();

        $browser->post('/sa/en/account/phone', ['phone' => '+966512345678'])
            ->assertRedirect('/sa/en/account?tab=phone');

        // The number is not on the account yet: a code has gone to it, and nothing more.
        expect(DB::table('access.customers')->where('id', $customerId)->value('phone'))->toBeNull();

        $browser->post('/sa/en/account/phone/code', [
            'code' => RecordingSecurityMessages::installed()->lastCode(),
        ])->assertRedirect('/sa/en/account?tab=phone');

        expect(DB::table('access.customers')->where('id', $customerId)->value('phone'))->toBe('+966512345678')
            ->and(DB::table('access.customers')->where('id', $customerId)->value('phone_verified_at'))->not->toBeNull();
    });

    it('lets them order once both their address and their number are confirmed', function () {
        [$customerId, $browser] = accountPageCustomer();

        DB::table('access.customers')->where('id', $customerId)->update(['email_verified_at' => now()]);
        $browser->post('/sa/en/account/phone', ['phone' => '+966512345678']);
        $browser->post('/sa/en/account/phone/code', ['code' => RecordingSecurityMessages::installed()->lastCode()]);

        $browser->get('/sa/en/account')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('phone', '+966512345678')
                ->where('phoneVerified', true)
                ->where('emailVerified', true)
                ->where('mayOrder', true)
            );
    });
});
