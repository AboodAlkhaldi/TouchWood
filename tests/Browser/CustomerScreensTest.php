<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Customers seen by staff, in a real browser (stage 2b step 4, frontend.md §3.7, G1 and G2).
|
| The feature tests beside these prove what the screens are handed. These prove a person can use
| them: that the list draws and a row opens somebody, and that blocking an account really asks for
| a reason and really changes what the screen says afterwards.
|
| Deliberately no RefreshDatabase, for the reason written at the top of StaffScreensTest, so every
| customer this file makes has an address of its own.
*/

const CUSTOMER_SCREEN_PASSWORD = 'a long enough password';

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/** A new Super Admin's email address, for signing in through the real screens. */
function customerScreenSuperAdminEmail(): string
{
    return (string) DB::table('access.staff_users')
        ->where('id', Fx::staff(superAdmin: true))
        ->value('email');
}

it('draws the list, opens a customer, and blocks them with a reason', function () {
    $email = strtolower((string) Str::ulid()).'@example.test';
    $customerId = Fx::customer($email, 'sa');

    $page = visit('/admin/sign-in')
        ->type('#email', customerScreenSuperAdminEmail())
        ->type('#password', CUSTOMER_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/customers');

    $page->assertSee('Customers')
        ->assertSee($email)
        ->click("[data-test=\"customer-{$customerId}\"]")
        ->assertPathIs("/admin/customers/{$customerId}")
        // Read only, except for the actions: the screen says so in as many words.
        ->assertSee('A customer')
        ->assertSee('Block the account');

    // A reason is asked for before anything happens: the audit entry is only as useful as the
    // sentence somebody wrote in it.
    $page->click('[data-test="block"]')
        ->type('#reason-block', 'Chargebacks on three orders.')
        ->click('[data-test="confirm-block"]')
        ->assertSee('Blocked')
        // And what can happen next is the other way round now.
        ->assertSee('Unblock the account')
        ->assertNoJavaScriptErrors();

    expect(DB::table('access.customers')->where('id', $customerId)->value('status'))->toBe('BLOCKED');
});
