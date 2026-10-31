<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Command\ChooseSignInMethod\ChooseSignInMethod;
use Modules\Access\Application\Command\ChooseSignInMethod\ChooseSignInMethodHandler;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdmin;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdminHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\StaffSignInMethods;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\SignInMethodRequired;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Settings\SettingValues;
use Shared\Application\Unauthorized;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\AuthenticatorFixtures as App;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

/*
| Staff sign-in by an authenticator app (access.md amendment 91), each of the owner's eight decisions of
| 2026-10-31 through the pages a person uses: the settings, the SMS first sign-in and the setup after
| it, the app's code at sign-in, recovery codes, the resets, the limits, the password before each
| change, and the audit entries.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * The right password from this browser: where it leads.
 *
 * @return TestResponse<Response>
 */
function authenticatorPassword(AdminBrowser $browser, string $staffId): TestResponse
{
    return $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ]);
}

/**
 * Signed in by SMS: the password, then the code that went to their phone.
 */
function authenticatorSignInBySms(AdminBrowser $browser, string $staffId): void
{
    authenticatorPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');
}

/**
 * One prop of the page an Inertia response renders.
 *
 * @param  TestResponse<Response>  $response
 */
function authenticatorProp(TestResponse $response, string $name): mixed
{
    $found = null;

    $response->assertInertia(function (AssertableInertia $page) use ($name, &$found): void {
        /** @var array<string, mixed> $props */
        $props = $page->toArray()['props'];
        $found = $props[$name] ?? null;
    });

    return $found;
}

/**
 * Sets the app up through the pages: the password, the QR code's key, the first code.
 *
 * @return array{secret: string, codes: list<string>}
 */
function authenticatorSetUp(AdminBrowser $browser): array
{
    $browser->post('/admin/authenticator/setup', ['current_password' => Fx::STAFF_PASSWORD])->assertRedirect('/admin/authenticator/setup');
    $key = authenticatorProp($browser->get('/admin/authenticator/setup')->assertOk(), 'manualKey');
    $secret = str_replace(' ', '', is_string($key) ? $key : '');

    $browser->post('/admin/authenticator/setup/code', ['code' => App::code($secret)])->assertRedirect('/admin/authenticator/recovery-codes');
    $codes = authenticatorProp($browser->get('/admin/authenticator/recovery-codes')->assertOk(), 'codes');

    $shown = [];

    foreach (is_array($codes) ? $codes : [] as $code) {
        if (is_string($code)) {
            $shown[] = $code;
        }
    }

    return ['secret' => $secret, 'codes' => $shown];
}

/**
 * Refused, with the refusal said on the form.
 *
 * @param  TestResponse<Response>  $response
 */
function authenticatorRefused(TestResponse $response): void
{
    expect(AdminBrowser::formError($response))->not->toBeNull();
}

function authenticatorAudits(string $action, string $staffId): int
{
    return Fx::audits($action, $staffId);
}

describe('decision 1: the methods, a Super Admin setting', function () {
    it('starts with the authenticator app alone at go-live', function () {
        $defaults = [];

        foreach (StaffSecuritySettings::definitions() as $definition) {
            $defaults[$definition->key] = $definition->default;
        }

        expect($defaults[StaffSecuritySettings::SIGN_IN_BY_SMS])->toBeFalse()
            ->and($defaults[StaffSecuritySettings::SIGN_IN_BY_AUTHENTICATOR])->toBeTrue();

        // And with nothing stored, that is what is read (the suite stores SMS alone for older tests).
        DB::table('platform.settings')->whereIn('key', [StaffSecuritySettings::SIGN_IN_BY_SMS, StaffSecuritySettings::SIGN_IN_BY_AUTHENTICATOR])->delete();
        app(SettingValues::class)->invalidate();
        $settings = app(StaffSecuritySettings::class);

        expect($settings->signInBySms())->toBeFalse()->and($settings->signInByAuthenticator())->toBeTrue();
    });

    it('lets a Super Admin change them, and nobody else - not an admin holding every store\'s staff settings', function () {
        Fx::actAsAdmin(['*'], [AccessPermissions::STAFF_SETTINGS_UPDATE]);

        expect(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(StaffSecuritySettings::SIGN_IN_BY_SMS, null, true)))
            ->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(UpdateSettingHandler::class)->handle(new UpdateSetting(StaffSecuritySettings::SIGN_IN_BY_AUTHENTICATOR, null, true));

        expect(app(StaffSecuritySettings::class)->signInByAuthenticator())->toBeTrue()
            ->and(DB::table('platform.audit_entries')->where('action', 'platform.setting.updated')->get(['changes'])
                ->contains(fn (object $entry): bool => str_contains((string) $entry->changes, StaffSecuritySettings::SIGN_IN_BY_AUTHENTICATOR)))->toBeTrue();
    });

    it('keeps one ticked: the last one cannot be turned off', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        // SMS alone is on (tests/Pest.php): turning SMS off would leave none.
        expect(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(StaffSecuritySettings::SIGN_IN_BY_SMS, null, false)))
            ->toThrow(SignInMethodRequired::class);

        // Both on, either one may go - and then the other is the last.
        app(UpdateSettingHandler::class)->handle(new UpdateSetting(StaffSecuritySettings::SIGN_IN_BY_AUTHENTICATOR, null, true));
        app(UpdateSettingHandler::class)->handle(new UpdateSetting(StaffSecuritySettings::SIGN_IN_BY_SMS, null, false));

        expect(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(StaffSecuritySettings::SIGN_IN_BY_AUTHENTICATOR, null, false)))
            ->toThrow(SignInMethodRequired::class);
        expect(app(StaffSecuritySettings::class)->signInByAuthenticator())->toBeTrue();
    });

    it('lets each staff member choose while both are on, and nobody choose while one is', function () {
        App::methods(sms: true, authenticator: true);
        $staffId = Fx::staff();
        App::giveApp($staffId);
        $browser = new AdminBrowser;

        authenticatorPassword($browser, $staffId)->assertRedirect('/admin/sign-in/app');
        $browser->post('/admin/sign-in/app', ['code' => App::code(App::giveApp($staffId)['secret'])])->assertRedirect('/admin');

        $browser->post('/admin/account/sign-in-method', ['method' => 'SMS'])->assertRedirect('/admin/account?tab=security');
        expect(authenticatorAudits('access.staff_user.sign_in_method_chosen', $staffId))->toBe(1)
            ->and(DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->value('in_use'))->toBeFalse();

        // Their next sign-in is by SMS; the app is kept.
        $browser->post('/admin/sign-out');
        authenticatorPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');

        // With one box, there is nothing to choose.
        App::methods(sms: false, authenticator: true);
        Fx::actAsStaff($staffId);
        expect(fn () => app(ChooseSignInMethodHandler::class)
            ->handle(new ChooseSignInMethod('SMS')))
            ->toThrow(InvalidAccessAttribute::class);
    });

    it('sends someone who chooses the app, with none set up, straight to its setup', function () {
        App::methods(sms: true, authenticator: true);
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        authenticatorSignInBySms($browser, $staffId);

        $browser->post('/admin/account/sign-in-method', ['method' => 'AUTHENTICATOR'])->assertRedirect('/admin/authenticator/setup');
        $browser->get('/admin/staff')->assertRedirect('/admin/authenticator/setup');

        authenticatorSetUp($browser);
        $browser->get('/admin')->assertOk();
        expect(DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->value('in_use'))->toBeTrue();
    });
});

describe('decision 2: the SMS first sign-in, then the setup before the panel', function () {
    it('verifies by SMS as today, then sends the session to the setup until the app is confirmed', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        authenticatorSignInBySms($browser, $staffId);
        expect(RecordingSecurityMessages::installed()->lastCode())->not->toBe('');

        // Every panel page sends it to the setup; only the setup and signing out are reached.
        $browser->get('/admin')->assertRedirect('/admin/authenticator/setup');
        $browser->get('/admin/account')->assertRedirect('/admin/authenticator/setup');
        $browser->post('/admin/account/notifications', [])->assertRedirect('/admin/authenticator/setup');
        $page = $browser->get('/admin/authenticator/setup')->assertOk();
        expect(authenticatorProp($page, 'required'))->toBeTrue()
            ->and(authenticatorProp($page, 'qrSvg'))->toBeNull();

        $app = authenticatorSetUp($browser);

        expect($app['codes'])->toHaveCount(10);
        $browser->get('/admin')->assertOk();
        expect(authenticatorAudits('access.staff_user.authenticator_set_up', $staffId))->toBe(1);
    });

    it('asks the app\'s code, not an SMS, at the next sign-in, and a trusted browser skips it as before', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $secret = App::giveApp($staffId)['secret'];
        $browser = new AdminBrowser;
        $sent = count(RecordingSecurityMessages::installed()->codes);

        authenticatorPassword($browser, $staffId)->assertRedirect('/admin/sign-in/app');
        expect(RecordingSecurityMessages::installed()->codes)->toHaveCount($sent);
        $browser->get('/admin/sign-in/code')->assertRedirect('/admin/sign-in');
        $browser->get('/admin/sign-in/app')->assertOk();

        $browser->post('/admin/sign-in/app', ['code' => App::code($secret), 'trust_browser' => true])->assertRedirect('/admin');
        $browser->get('/admin')->assertOk();
        $browser->post('/admin/sign-out');

        authenticatorPassword($browser, $staffId)->assertRedirect('/admin');
    });

    it('sends a trusted browser to the setup when the app is required and none is set up', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        $browser->post('/admin/sign-in', ['email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'), 'password' => Fx::STAFF_PASSWORD]);
        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode(), 'trust_browser' => true])->assertRedirect('/admin');
        $browser->post('/admin/sign-out');

        App::methods(sms: false, authenticator: true);
        authenticatorPassword($browser, $staffId)->assertRedirect('/admin');
        $browser->get('/admin')->assertRedirect('/admin/authenticator/setup');
    });

    it('sends a Super Admin who accepts their invitation to the setup, after the phone\'s SMS code', function () {
        App::methods(sms: false, authenticator: true);
        app(CreateSuperAdminHandler::class)->handle(new CreateSuperAdmin('owner@example.test', 'Abood', 'Owner', 'Founder', '1995-01-01', 'SA', '+966501112233', 'ar'));
        $token = RecordingSecurityMessages::installed()->lastInvitationToken();
        $browser = new AdminBrowser;

        $browser->post("/admin/invitation/{$token}", ['password' => Fx::STAFF_PASSWORD, 'phone' => '+966501112233']);
        $browser->post("/admin/invitation/{$token}/code", ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

        $browser->get('/admin')->assertRedirect('/admin/authenticator/setup');
        authenticatorSetUp($browser);
        $browser->get('/admin')->assertOk();
    });
});

describe('decision 3: unticking a method its users use', function () {
    it('moves app users to SMS from their next sign-in, and keeps their app', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        App::giveApp($staffId);

        App::methods(sms: true, authenticator: false);
        authenticatorPassword(new AdminBrowser, $staffId)->assertRedirect('/admin/sign-in/code');
        expect(DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->whereNotNull('secret')->exists())->toBeTrue();

        // Ticked again, the kept app is used again.
        App::methods(sms: false, authenticator: true);
        authenticatorPassword(new AdminBrowser, $staffId)->assertRedirect('/admin/sign-in/app');
    });

    it('signs anyone without the app in once more by SMS when SMS is unticked, then asks for the setup', function () {
        App::methods(sms: true, authenticator: true);
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        App::methods(sms: false, authenticator: true);
        authenticatorSignInBySms($browser, $staffId);
        $browser->get('/admin')->assertRedirect('/admin/authenticator/setup');
    });
});

describe('decision 4: recovery codes and resets', function () {
    it('keeps the ten codes only as hashes and shows them once', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        authenticatorSignInBySms($browser, $staffId);
        $codes = authenticatorSetUp($browser)['codes'];

        expect($codes)->toHaveCount(10)->and(array_unique($codes))->toHaveCount(10);
        $stored = DB::table('access.staff_recovery_codes')->where('staff_user_id', $staffId)->pluck('code_hash')->all();
        expect($stored)->toHaveCount(10);

        foreach ($codes as $code) {
            expect(in_array($code, $stored, true) || in_array(str_replace('-', '', $code), $stored, true))->toBeFalse();
        }

        // Opened again, the page has nothing to show and goes on.
        $browser->get('/admin/authenticator/recovery-codes')->assertRedirect('/admin/account?tab=security');
    });

    it('signs in with a recovery code in place of an app code, once, and audits it', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $codes = App::giveApp($staffId)['codes'];

        $browser = new AdminBrowser;
        authenticatorPassword($browser, $staffId)->assertRedirect('/admin/sign-in/app');
        // Typed in capitals and without its dash, it is the same code.
        $browser->post('/admin/sign-in/app', ['code' => strtoupper(str_replace('-', '', $codes[0])), 'recovery' => true])->assertRedirect('/admin');
        expect(authenticatorAudits('access.staff_user.recovery_code_used', $staffId))->toBe(1);
        $browser->post('/admin/sign-out');

        $again = new AdminBrowser;
        authenticatorPassword($again, $staffId)->assertRedirect('/admin/sign-in/app');
        authenticatorRefused($again->post('/admin/sign-in/app', ['code' => $codes[0], 'recovery' => true]));
        $again->get('/admin')->assertRedirect('/admin/sign-in');
    });

    it('replaces every code with new ones, after the password', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $app = App::giveApp($staffId);
        $browser = new AdminBrowser;
        authenticatorPassword($browser, $staffId);
        $browser->post('/admin/sign-in/app', ['code' => App::code($app['secret'])])->assertRedirect('/admin');

        authenticatorRefused($browser->post('/admin/account/authenticator/recovery-codes', ['current_password' => 'not the password at all']));
        $browser->post('/admin/account/authenticator/recovery-codes', ['current_password' => Fx::STAFF_PASSWORD])->assertRedirect('/admin/authenticator/recovery-codes');
        $new = authenticatorProp($browser->get('/admin/authenticator/recovery-codes')->assertOk(), 'codes');

        expect($new)->toBeArray()->toHaveCount(10)
            ->and(authenticatorAudits('access.staff_user.recovery_codes_renewed', $staffId))->toBe(1);

        $browser->post('/admin/sign-out');
        $other = new AdminBrowser;
        authenticatorPassword($other, $staffId);
        authenticatorRefused($other->post('/admin/sign-in/app', ['code' => $app['codes'][1], 'recovery' => true]));
    });

    it('lets a Super Admin reset someone\'s app from the staff page, audited - and nobody else', function () {
        $staffId = Fx::staff();
        App::giveApp($staffId);

        $adminId = Fx::staffWith([AccessPermissions::STAFF_VIEW, AccessPermissions::STAFF_UPDATE], ['*'], RoleLevel::Admin);
        Fx::actAsStaff($adminId);
        authenticatorRefused(Pest\Laravel\post("/admin/staff/{$staffId}/authenticator/reset"));
        expect(DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->exists())->toBeTrue();

        $superAdminId = Fx::staff(superAdmin: true);
        Fx::actAsStaff($superAdminId);
        $page = Pest\Laravel\get("/admin/staff/{$staffId}")->assertOk();
        expect(authenticatorProp($page, 'mayResetAuthenticator'))->toBeTrue();

        expect(AdminBrowser::formError(Pest\Laravel\post("/admin/staff/{$staffId}/authenticator/reset")))->toBeNull();
        expect(DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(DB::table('access.staff_recovery_codes')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(authenticatorAudits('access.staff_user.authenticator_reset', $staffId))->toBe(1);

        // They sign in next by SMS, and set a new app up when it is their method (requests here act as the
        // Super Admin bound above, so the method is read rather than signed in with).
        App::methods(sms: false, authenticator: true);
        expect(app(StaffSignInMethods::class)->mustSetUp($staffId))->toBeTrue();
    });

    it('resets a Super Admin\'s app only by another Super Admin, never their own from the staff page', function () {
        $first = Fx::staff(superAdmin: true);
        $second = Fx::staff(superAdmin: true);
        App::giveApp($first);

        Fx::actAsStaff($first);
        authenticatorRefused(Pest\Laravel\post("/admin/staff/{$first}/authenticator/reset"));
        expect(DB::table('access.staff_authenticators')->where('staff_user_id', $first)->exists())->toBeTrue();

        Fx::actAsStaff($second);
        expect(authenticatorProp(Pest\Laravel\get("/admin/staff/{$first}")->assertOk(), 'mayResetAuthenticator'))->toBeTrue();
        expect(AdminBrowser::formError(Pest\Laravel\post("/admin/staff/{$first}/authenticator/reset")))->toBeNull();
        expect(DB::table('access.staff_authenticators')->where('staff_user_id', $first)->exists())->toBeFalse();
    });

    it('resets a lone Super Admin\'s app from the server, audited, and refuses anyone else', function () {
        $superAdminId = Fx::staff(superAdmin: true);
        App::giveApp($superAdminId);
        $staffId = Fx::staff();
        App::giveApp($staffId);
        $email = fn (string $id): string => (string) DB::table('access.staff_users')->where('id', $id)->value('email');

        $run = function (string $email): PendingCommand {
            $pending = artisan('access:super-admin:reset-authenticator', ['email' => $email]);

            return $pending instanceof PendingCommand ? $pending : throw new LogicException('Expected a pending command');
        };

        $run($email($staffId))->assertFailed();
        $run($email($superAdminId))->assertSuccessful();

        expect(DB::table('access.staff_authenticators')->where('staff_user_id', $superAdminId)->exists())->toBeFalse()
            ->and(DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->exists())->toBeTrue()
            ->and(authenticatorAudits('access.staff_user.authenticator_reset', $superAdminId))->toBe(1);
    });
});

describe('decision 5: the limits, used codes, and the secret at rest', function () {
    it('ends the sign-in after as many wrong codes as an SMS code allows, and asks the password again', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $secret = App::giveApp($staffId)['secret'];
        $browser = new AdminBrowser;
        authenticatorPassword($browser, $staffId)->assertRedirect('/admin/sign-in/app');
        $tries = app(StaffSecuritySettings::class)->codeAttempts();

        for ($i = 1; $i < $tries; $i++) {
            authenticatorRefused($browser->post('/admin/sign-in/app', ['code' => App::wrongCode($secret)]));
            $browser->get('/admin/sign-in/app')->assertOk();
        }

        $browser->post('/admin/sign-in/app', ['code' => App::wrongCode($secret)])->assertRedirect('/admin/sign-in');
        // That sign-in is over: even the right code needs the password again.
        $browser->get('/admin/sign-in/app')->assertRedirect('/admin/sign-in');
        authenticatorRefused($browser->post('/admin/sign-in/app', ['code' => App::code($secret)]));
        $browser->get('/admin')->assertRedirect('/admin/sign-in');
    });

    it('opens the app step as often an hour as an SMS code is sent, and gives them back on success', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $secret = App::giveApp($staffId)['secret'];
        $perHour = app(StaffSecuritySettings::class)->codesPerHour();

        for ($i = 0; $i < $perHour; $i++) {
            authenticatorPassword(new AdminBrowser, $staffId)->assertRedirect('/admin/sign-in/app');
        }

        authenticatorRefused(authenticatorPassword(new AdminBrowser, $staffId));
        expect(DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->value('attempts'))->toBe(0);
        unset($secret);
    });

    it('refuses a code used once already', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $secret = App::giveApp($staffId)['secret'];
        $code = App::code($secret);

        $first = new AdminBrowser;
        authenticatorPassword($first, $staffId);
        $first->post('/admin/sign-in/app', ['code' => $code])->assertRedirect('/admin');

        $second = new AdminBrowser;
        authenticatorPassword($second, $staffId);
        authenticatorRefused($second->post('/admin/sign-in/app', ['code' => $code]));
        $second->get('/admin')->assertRedirect('/admin/sign-in');
        expect((int) DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->value('last_used_step'))->toBeGreaterThanOrEqual(App::step() - 1);
    });

    it('keeps the secret encrypted with the app\'s key, never in plain text', function () {
        $staffId = Fx::staff();
        $secret = App::giveApp($staffId)['secret'];
        $stored = (string) DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->value('secret');

        expect($stored)->not->toContain($secret)
            ->and(app(Encrypter::class)->decryptString($stored))->toBe($secret);
    });
});

describe('decision 6: the password before each change', function () {
    it('shows no QR code before the password, and counts a wrong one as at sign-in', function () {
        App::methods(sms: true, authenticator: true);
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        authenticatorSignInBySms($browser, $staffId);

        expect(authenticatorProp($browser->get('/admin/authenticator/setup')->assertOk(), 'qrSvg'))->toBeNull();
        authenticatorRefused($browser->post('/admin/authenticator/setup', ['current_password' => 'not the password at all']));
        expect(authenticatorProp($browser->get('/admin/authenticator/setup')->assertOk(), 'qrSvg'))->toBeNull();

        $browser->post('/admin/authenticator/setup', ['current_password' => Fx::STAFF_PASSWORD]);
        $page = $browser->get('/admin/authenticator/setup')->assertOk();
        expect((string) authenticatorProp($page, 'qrSvg'))->toStartWith('<?xml')->toContain('<svg');

        // Another browser of the same person, without giving the password there, sees no QR code.
        $other = new AdminBrowser;
        authenticatorSignInBySms($other, $staffId);
        expect(authenticatorProp($other->get('/admin/authenticator/setup')->assertOk(), 'qrSvg'))->toBeNull();
    });

    it('removes one\'s own app only with the password, audited, and sets a new one up when the app is the only method', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $secret = App::giveApp($staffId)['secret'];
        $browser = new AdminBrowser;
        authenticatorPassword($browser, $staffId);
        $browser->post('/admin/sign-in/app', ['code' => App::code($secret)])->assertRedirect('/admin');

        authenticatorRefused($browser->post('/admin/account/authenticator/remove', ['current_password' => 'not the password at all']));
        expect(DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->exists())->toBeTrue();

        $browser->post('/admin/account/authenticator/remove', ['current_password' => Fx::STAFF_PASSWORD])->assertRedirect('/admin/authenticator/setup');
        expect(DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(authenticatorAudits('access.staff_user.authenticator_removed', $staffId))->toBe(1);
        $browser->get('/admin')->assertRedirect('/admin/authenticator/setup');
    });

    it('replaces an app only once the new one\'s code is right, and records that it replaced one', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $old = App::giveApp($staffId)['secret'];
        $browser = new AdminBrowser;
        authenticatorPassword($browser, $staffId);
        $browser->post('/admin/sign-in/app', ['code' => App::code($old)])->assertRedirect('/admin');

        $browser->post('/admin/authenticator/setup', ['current_password' => Fx::STAFF_PASSWORD]);
        authenticatorRefused($browser->post('/admin/authenticator/setup/code', ['code' => '000000']));
        $secret = DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->value('secret');
        expect(app(Encrypter::class)->decryptString((string) $secret))->toBe($old);

        $new = authenticatorSetUp($browser)['secret'];
        expect(app(Encrypter::class)->decryptString((string) DB::table('access.staff_authenticators')->where('staff_user_id', $staffId)->value('secret')))->toBe($new)
            ->and(json_decode((string) DB::table('platform.audit_entries')->where('action', 'access.staff_user.authenticator_set_up')->where('subject_id', $staffId)->value('changes'), true))->toBe(['replaced' => [null, true]]);
    });
});

describe('decision 8: SMS stays for the rest', function () {
    it('still sends the SMS code of a first sign-in with the app alone on', function () {
        App::methods(sms: false, authenticator: true);
        $before = count(RecordingSecurityMessages::installed()->codes);

        authenticatorPassword(new AdminBrowser, Fx::staff())->assertRedirect('/admin/sign-in/code');

        expect(RecordingSecurityMessages::installed()->codes)->toHaveCount($before + 1)
            ->and(RecordingSecurityMessages::installed()->codes[$before]['kind'])->toBe('sign_in');
    });
});

it('names every new action in the audit log in both languages', function () {
    foreach (['authenticator_set_up', 'authenticator_removed', 'authenticator_reset', 'recovery_codes_renewed', 'recovery_code_used', 'sign_in_method_chosen'] as $action) {
        foreach (['en', 'ar'] as $locale) {
            expect(__("access::audit.staff_user.{$action}", [], $locale))->not->toBe("access::audit.staff_user.{$action}");
        }
    }
});
