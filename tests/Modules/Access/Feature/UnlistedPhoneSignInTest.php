<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Signing in with a phone whose country left the SMS countries list (amendment 90; owner, 2026-10-29):
| no code can reach it, so it counts as no phone. A Super Admin enters a new, listed number after the
| right password - the path a Super Admin whose phone was reset takes (amendment 14); anyone else is
| refused until an admin changes their phone.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Sessions live in the database, as in production: the admin session is read again on every request.
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/** A staff member whose saved, verified phone is from the United Arab Emirates, not on the list. */
function unlistedPhoneStaff(bool $superAdmin): string
{
    $staffId = Fx::staff(superAdmin: $superAdmin);
    DB::table('access.staff_users')->where('id', $staffId)->update(['phone' => '+971501234567']);

    return $staffId;
}

/**
 * @return TestResponse<Response>
 */
function unlistedPhoneSignIn(AdminBrowser $browser, string $staffId): TestResponse
{
    return $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ]);
}

it('asks a Super Admin whose phone SMS no longer reaches for a new number, and verifies it with the code', function () {
    $superAdmin = unlistedPhoneStaff(superAdmin: true);
    $browser = new AdminBrowser;

    unlistedPhoneSignIn($browser, $superAdmin)->assertRedirect('/admin/sign-in/phone');

    expect(RecordingSecurityMessages::installed()->codes)->toBe([]);

    $browser->post('/admin/sign-in/phone', ['phone' => '+966 50 777 7777'])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    $row = DB::table('access.staff_users')->where('id', $superAdmin)->first();

    expect(RecordingSecurityMessages::installed()->codes[0]['phone'])->toBe('+966507777777')
        ->and($row?->phone)->toBe('+966507777777')
        ->and($row?->phone_verified_at)->not->toBeNull()
        ->and(Fx::audits('access.staff_user.phone_verified', $superAdmin))->toBe(1);
});

it('refuses the Super Admin\'s new number when its country is not on the list either, in its own words', function () {
    $superAdmin = unlistedPhoneStaff(superAdmin: true);
    $browser = new AdminBrowser;
    unlistedPhoneSignIn($browser, $superAdmin)->assertRedirect('/admin/sign-in/phone');

    $refused = $browser->post('/admin/sign-in/phone', ['phone' => '+971 50 777 7777']);

    expect(AdminBrowser::formError($refused))->toBe((string) __('access::errors.invalid_access_attribute.rules.phone_country'))
        ->and(RecordingSecurityMessages::installed()->codes)->toBe([])
        ->and(DB::table('access.staff_users')->where('id', $superAdmin)->value('phone'))->toBe('+971501234567');
});

it('refuses anyone else until an admin changes their phone, sending no code', function () {
    $staffId = unlistedPhoneStaff(superAdmin: false);

    expect(AdminBrowser::formError(unlistedPhoneSignIn(new AdminBrowser, $staffId)))->toBe((string) __('access::errors.sign_in_refused.detail'))
        ->and(RecordingSecurityMessages::installed()->codes)->toBe([]);
});

it('signs in with a listed phone exactly as before', function () {
    $staffId = Fx::staff();
    $browser = new AdminBrowser;

    unlistedPhoneSignIn($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');
});

it('never shows the new-number screen to a wrong password', function () {
    $superAdmin = unlistedPhoneStaff(superAdmin: true);
    $browser = new AdminBrowser;

    $wrong = $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $superAdmin)->value('email'),
        'password' => 'not the password at all',
    ]);

    expect(AdminBrowser::formError($wrong))->toBe((string) __('access::errors.invalid_credentials.detail'))
        ->and($wrong->headers->get('Location'))->not->toContain('/admin/sign-in/phone');

    // And nothing waits there for that browser: a number posted now is refused like a stale step.
    expect(AdminBrowser::formError($browser->post('/admin/sign-in/phone', ['phone' => '+966507777777'])))->toBe((string) __('access::errors.invalid_code.detail'))
        ->and(RecordingSecurityMessages::installed()->codes)->toBe([]);
});

it('lets a browser trusted before the country left the list in, as it skips the code step altogether', function () {
    // Known and kept (amendment 90): a trusted browser never asks for a code, so the phone is not read.
    $superAdmin = Fx::staff(superAdmin: true);
    $browser = new AdminBrowser;
    unlistedPhoneSignIn($browser, $superAdmin)->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode(), 'trust_browser' => true])->assertRedirect('/admin');
    $browser->post('/admin/sign-out');

    DB::table('access.staff_users')->where('id', $superAdmin)->update(['phone' => '+971501234567']);

    unlistedPhoneSignIn($browser, $superAdmin)->assertRedirect('/admin');
});

it('starts the sign-in again when the phone\'s country leaves the list between the password and the code', function () {
    $staffId = Fx::staff();
    $browser = new AdminBrowser;
    unlistedPhoneSignIn($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    $code = RecordingSecurityMessages::installed()->lastCode();

    DB::table('ops.sms_countries')->where('country_code', 'SA')->delete();

    // Neither the code nor a resend: the sign-in starts again, never a phone error on the code screen.
    expect(AdminBrowser::formError($browser->post('/admin/sign-in/code/resend')))->toBe((string) __('access::errors.invalid_code.detail'))
        ->and(AdminBrowser::formError($browser->post('/admin/sign-in/code', ['code' => $code])))->toBe((string) __('access::errors.invalid_code.detail'))
        ->and(RecordingSecurityMessages::installed()->codes)->toHaveCount(1)
        ->and(Fx::audits('access.staff_user.signed_in', $staffId))->toBe(0);
});

it('refuses the Super Admin\'s new number when its country leaves the list before the code comes back', function () {
    $superAdmin = unlistedPhoneStaff(superAdmin: true);
    $browser = new AdminBrowser;
    unlistedPhoneSignIn($browser, $superAdmin)->assertRedirect('/admin/sign-in/phone');
    $browser->post('/admin/sign-in/phone', ['phone' => '+201001234567'])->assertRedirect('/admin/sign-in/code');

    DB::table('ops.sms_countries')->where('country_code', 'EG')->delete();

    $refused = $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()]);

    expect(AdminBrowser::formError($refused))->toBe((string) __('access::errors.invalid_access_attribute.rules.phone_country'))
        ->and(DB::table('access.staff_users')->where('id', $superAdmin)->value('phone'))->toBe('+971501234567')
        ->and(Fx::audits('access.staff_user.signed_in', $superAdmin))->toBe(0);
});

it('emails the account when the Super Admin\'s phone is replaced at sign-in, naming no number (owner, 2026-10-30)', function () {
    $superAdmin = unlistedPhoneStaff(superAdmin: true);
    $browser = new AdminBrowser;
    unlistedPhoneSignIn($browser, $superAdmin)->assertRedirect('/admin/sign-in/phone');
    $browser->post('/admin/sign-in/phone', ['phone' => '+966507777777']);

    expect(RecordingSecurityMessages::installed()->phoneChanges)->toBe([]);

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    expect(RecordingSecurityMessages::installed()->phoneChanges)->toBe([
        ['staff' => $superAdmin, 'to' => (string) DB::table('access.staff_users')->where('id', $superAdmin)->value('email')],
    ]);
});

it('sends no such email on an ordinary sign-in', function () {
    $staffId = Fx::staff(superAdmin: true);
    $browser = new AdminBrowser;
    unlistedPhoneSignIn($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    expect(RecordingSecurityMessages::installed()->phoneChanges)->toBe([]);
});
