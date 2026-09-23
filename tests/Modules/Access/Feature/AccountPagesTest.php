<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\PlatformPermissions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A session that survives a redirect: phpunit.xml forces "array", which keeps nothing between
    // requests, and every screen here answers a form with a redirect (Access amendment 12).
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/*
| Stage 2b, step 2 - "Account & settings", B1-B4 (frontend.md §3.2).
|
| Every helper here is named after this file's subject. A function declared in a Pest file is global
| to the whole suite, so two files sharing a name stop every run (project conventions).
*/

/**
 * A browser signed in completely as this staff member: the password, then the code.
 */
function accountSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.4.0.'.random_int(20, 250));

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
 * An ordinary staff member of the Saudi store, signed in. They hold no media permission at all,
 * which is the point of the picture test below.
 *
 * @return array{0: AdminBrowser, 1: string}
 */
function accountOrdinary(): array
{
    $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);

    return [accountSignIn($staffId), $staffId];
}

/**
 * The validation message a field came back with, if any.
 *
 * Read off the response's own session rather than through assertSessionHasErrors: AdminBrowser
 * gives every request a fresh session store, so the test case's own session never sees these.
 *
 * @param  TestResponse<Response>  $response
 */
function accountFieldError(TestResponse $response, string $field): ?string
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->first($field);
    }

    // Once saved, a JSON session holds the bag as an array (session.serialization = json).
    $message = is_array($errors) ? ($errors['default']['messages'][$field][0] ?? null) : null;

    return is_string($message) ? $message : null;
}

/**
 * The phone codes actually sent for a phone **change**. Signing in sends one too, so a bare "no
 * codes were sent" would be true of nothing.
 *
 * @return list<array{phone: string, locale: string, code: string, kind: string}>
 */
function accountVerifyCodes(): array
{
    return array_values(array_filter(
        RecordingSecurityMessages::installed()->codes,
        static fn (array $code): bool => $code['kind'] === 'verify',
    ));
}

/**
 * The profile form's fields, as the screen sends them, with anything the test wants changed.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function accountProfileForm(array $changes = []): array
{
    return [
        'first_name' => 'Staff',
        'last_name' => 'Member',
        'job_title' => 'Tester',
        'date_of_birth' => '1990-01-01',
        'country' => 'SA',
        'address' => null,
        'locale' => 'en',
        ...$changes,
    ];
}

describe('the account screen', function () {
    it('shows a staff member their own account, and only their own', function () {
        [$browser, $staffId] = accountOrdinary();

        $browser->get('/admin/account')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->component('Access/Admin/Account/Account')
                ->where('firstName', 'Staff')
                ->where('lastName', 'Member')
                ->where('jobTitle', 'Tester')
                ->where('dateOfBirth', '1990-01-01')
                ->where('country', 'SA')
                // Their communication language, which the fixture leaves as English.
                ->where('locale', 'en')
                // The whole number, not masked: their own account, after a password and a code
                // (2026-09-23).
                ->where('phone', (string) DB::table('access.staff_users')->where('id', $staffId)->value('phone'))
                // An ordinary staff member is told to ask an admin; only a Super Admin gets the
                // button (amendment 17).
                ->where('canChangeEmail', false)
                ->where('pendingEmail', null)
                ->where('tab', 'account')
                // The rule in words comes from the setting, never from the screen.
                ->where('passwordMinLength', 12)
            );
    });

    it('opens on the tab it was asked for, and ignores one that does not exist', function () {
        [$browser] = accountOrdinary();

        $browser->get('/admin/account?tab=security')
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('tab', 'security'));

        // Nobody types this; it arrives from our own links. A value we do not know opens the first
        // tab rather than failing.
        $browser->get('/admin/account?tab=nonsense')
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('tab', 'account'));
    });

    it('carries every topic, in the order they are declared, whatever is switched on', function () {
        [$browser] = accountOrdinary();

        $browser->get('/admin/account')->assertInertia(function (AssertableInertia $inertia) {
            /** @var list<array{topic: string, email: bool, panel: bool}> $topics */
            $topics = $inertia->toArray()['props']['notifications'];

            expect(array_column($topics, 'topic'))
                ->toBe(['NEW_ORDERS', 'COMPANY_APPLICATIONS', 'LOW_STOCK', 'CAMPAIGN_EXPIRY'])
                // A new staff member's: every in-panel toggle on, every email toggle off.
                ->and(array_column($topics, 'panel'))->toBe([true, true, true, true])
                ->and(array_column($topics, 'email'))->toBe([false, false, false, false]);
        });
    });

    it('names every country in the language the panel is being read in', function () {
        [$browser] = accountOrdinary();

        $browser->post('/admin/preferences', ['preference' => 'locale', 'value' => 'ar']);

        $browser->get('/admin/account')->assertInertia(function (AssertableInertia $inertia) {
            /** @var list<array{code: string, name: string}> $countries */
            $countries = $inertia->toArray()['props']['countries'];
            $names = array_column($countries, 'name', 'code');

            expect($countries)->toHaveCount(count(array_unique(array_column($countries, 'code'))))
                ->and(count($countries))->toBeGreaterThan(200)
                // Named by ICU in the page's language, not by a list written into the repository.
                ->and($names['SA'] ?? '')->toBe('المملكة العربية السعودية');
        });
    });

    it('ships its own words and the layout\'s, so no key is rendered raw at a person', function () {
        [$browser] = accountOrdinary();

        $browser->get('/admin/account')->assertInertia(function (AssertableInertia $inertia) {
            /** @var array<string, string> $words */
            $words = $inertia->toArray()['props']['translations'];

            expect($words)->toHaveKey('access::account.title')
                ->and($words)->toHaveKey('access::account.topic.NEW_ORDERS')
                // The layout's own file. A screen that ships its own words but not the frame's
                // shows the frame's keys raw (step 1, found by running it).
                ->and($words)->toHaveKey('admin.account_settings')
                ->and($words)->toHaveKey('admin.close')
                // Still only the files this screen named.
                ->and($words)->not->toHaveKey('access::permissions.access.staff.invite');
        });
    });

    it('sends somebody who is not signed in to the sign-in page', function () {
        (new AdminBrowser)->get('/admin/account')->assertRedirect('/admin/sign-in');
        (new AdminBrowser)->post('/admin/account/profile', accountProfileForm())->assertRedirect('/admin/sign-in');
        (new AdminBrowser)->post('/admin/account/notifications', ['topic' => 'LOW_STOCK', 'email' => true, 'panel' => true])
            ->assertRedirect('/admin/sign-in');
    });
});

describe('B1 - the profile', function () {
    it('saves the profile and the communication language, and says so', function () {
        [$browser, $staffId] = accountOrdinary();

        $response = $browser->post('/admin/account/profile', accountProfileForm([
            'first_name' => 'Noura',
            'job_title' => 'Buyer',
            'country' => 'EG',
            'address' => 'A street in Cairo',
            'locale' => 'ar',
        ]));

        $response->assertRedirect('/admin/account?tab=account');
        expect(AdminBrowser::flashed($response, 'status'))->toBe('Saved.');

        $row = DB::table('access.staff_users')->where('id', $staffId)->first();

        expect($row?->first_name)->toBe('Noura')
            ->and($row?->job_title)->toBe('Buyer')
            ->and($row?->country)->toBe('EG')
            ->and($row?->address)->toBe('A street in Cairo')
            // The communication language, which is what emails and codes are written in. It is not
            // the panel's displayed language, which lives in a cookie (amendment 16).
            ->and($row?->locale)->toBe('ar');
    });

    it('refuses a date of birth that is not one, in the person\'s own language', function () {
        [$browser, $staffId] = accountOrdinary();
        $before = DB::table('access.staff_users')->where('id', $staffId)->value('date_of_birth');

        $response = $browser->post('/admin/account/profile', accountProfileForm(['date_of_birth' => '2999-01-01']));

        $response->assertRedirect();

        // A business error is the form's message, not a field's - Access refused the value, the
        // form request did not (frontend.md §1.7).
        expect(AdminBrowser::formError($response))->not->toBeNull()
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('date_of_birth'))->toBe($before);
    });

    it('lands a missing name on its own field rather than as a form error', function () {
        [$browser] = accountOrdinary();

        $response = $browser->post('/admin/account/profile', accountProfileForm(['first_name' => '']));

        $response->assertRedirect();

        // Read off the response's own session, as AdminBrowser::formError does: this browser gives
        // each request a fresh store, so the test case's own session holds nothing (AdminBrowser).
        expect(accountFieldError($response, 'first_name'))->not->toBeNull()
            // The distinction the test is for: a shape error belongs under its field, and a
            // business error belongs to the form (frontend.md §1.7).
            ->and(AdminBrowser::formError($response))->toBeNull();
    });

    it('lets a staff member with no media permission set their own picture', function () {
        // §7, and P1: access.own_account.update is automatic for every staff member, and
        // platform.media.upload is not. Before P1 this person simply could not have a picture.
        Storage::fake('public');
        Storage::fake('local', ['serve' => true]);
        Queue::fake();

        [$browser, $staffId] = accountOrdinary();

        // Read off their role rather than asked of the authorizer, which would answer for whoever
        // the test is bound as rather than for the person holding this browser.
        $held = Fx::rolePermissions(Fx::roleOf($staffId));

        expect($held)->toBe([AccessPermissions::STAFF_VIEW])
            ->and(in_array(PlatformPermissions::MEDIA_UPLOAD, $held, true))->toBeFalse();

        $browser->post('/admin/account/profile', accountProfileForm([
            'avatar' => UploadedFile::fake()->image('me.jpg', 40, 40),
        ]))->assertRedirect('/admin/account?tab=account');

        expect(DB::table('access.staff_users')->where('id', $staffId)->value('avatar_media_id'))->not->toBeNull();
    });

    it('leaves the picture alone when the form does not mention one, and removes it when asked', function () {
        Storage::fake('public');
        Storage::fake('local', ['serve' => true]);
        Queue::fake();

        [$browser, $staffId] = accountOrdinary();

        $browser->post('/admin/account/profile', accountProfileForm([
            'avatar' => UploadedFile::fake()->image('me.jpg', 40, 40),
        ]));

        $set = DB::table('access.staff_users')->where('id', $staffId)->value('avatar_media_id');
        expect($set)->not->toBeNull();

        // Saving the rest of the form must not quietly drop the picture: the id is read from the
        // account, never sent back by the browser.
        $browser->post('/admin/account/profile', accountProfileForm(['job_title' => 'Senior buyer']));

        expect(DB::table('access.staff_users')->where('id', $staffId)->value('avatar_media_id'))->toBe($set);

        $browser->post('/admin/account/profile', accountProfileForm(['remove_avatar' => true]));

        expect(DB::table('access.staff_users')->where('id', $staffId)->value('avatar_media_id'))->toBeNull();
    });
});

describe('B1 - the email', function () {
    it('offers a Super Admin the change, and sends the link to the new address', function () {
        $superAdminId = Fx::staff(StaffStatus::Active, superAdmin: true);
        $browser = accountSignIn($superAdminId);

        $browser->get('/admin/account')
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('canChangeEmail', true));

        $response = $browser->post('/admin/account/email', ['email' => 'new.address@touchwood.test']);
        $response->assertRedirect('/admin/account?tab=account');

        $sent = RecordingSecurityMessages::installed()->emailChanges;

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['to'])->toBe('new.address@touchwood.test');

        // Nothing has changed yet: the address changes when the link is used, and until then the
        // screen says it is pending (amendment 17).
        expect(DB::table('access.staff_users')->where('id', $superAdminId)->value('email'))
            ->not->toBe('new.address@touchwood.test');

        $browser->get('/admin/account')->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('pendingEmail', 'new.address@touchwood.test'));
    });

    it('refuses an ordinary staff member who asks for it anyway', function () {
        // The button is not on their screen, which is not the protection: the handler refuses it.
        [$browser] = accountOrdinary();

        $response = $browser->post('/admin/account/email', ['email' => 'somewhere.else@touchwood.test']);

        $response->assertForbidden();
        expect(RecordingSecurityMessages::installed()->emailChanges)->toBe([]);
    });
});

describe('B2 - the phone', function () {
    it('refuses a wrong password and changes nothing', function () {
        [$browser, $staffId] = accountOrdinary();
        $before = DB::table('access.staff_users')->where('id', $staffId)->value('phone');

        $response = $browser->post('/admin/account/phone', [
            'phone' => Fx::phone(),
            'current_password' => 'not the right password',
        ]);

        $response->assertRedirect();

        expect(AdminBrowser::formError($response))->not->toBeNull()
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('phone'))->toBe($before)
            // No code went anywhere. Signing in sent one of its own, so only the change's are
            // counted here.
            ->and(accountVerifyCodes())->toBe([]);
    });

    it('sends a code to the new number and keeps the old one until the code is right', function () {
        [$browser, $staffId] = accountOrdinary();
        $old = (string) DB::table('access.staff_users')->where('id', $staffId)->value('phone');
        $new = Fx::phone();

        $response = $browser->post('/admin/account/phone', [
            'phone' => $new,
            'current_password' => Fx::STAFF_PASSWORD,
        ]);

        $response->assertRedirect('/admin/account?tab=account');

        $messages = RecordingSecurityMessages::installed();
        $last = end($messages->codes);

        expect($last)->not->toBeFalse()
            ->and($last === false ? '' : $last['phone'])->toBe($new)
            // Still the old number: it stays in use until the code is entered (access.md §1.4).
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('phone'))->toBe($old);

        $browser->post('/admin/account/phone/code', ['code' => $messages->lastCode()])
            ->assertRedirect('/admin/account?tab=account');

        expect(DB::table('access.staff_users')->where('id', $staffId)->value('phone'))->toBe($new);
    });

    it('refuses a wrong code and leaves the number where it was', function () {
        [$browser, $staffId] = accountOrdinary();
        $old = (string) DB::table('access.staff_users')->where('id', $staffId)->value('phone');

        $browser->post('/admin/account/phone', [
            'phone' => Fx::phone(),
            'current_password' => Fx::STAFF_PASSWORD,
        ])->assertRedirect();

        $response = $browser->post('/admin/account/phone/code', ['code' => '000000']);

        $response->assertRedirect();

        expect(AdminBrowser::formError($response))->not->toBeNull()
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('phone'))->toBe($old);
    });
});

describe('B3 - the password', function () {
    it('changes it and says every other session was signed out', function () {
        [$browser, $staffId] = accountOrdinary();
        $before = (string) DB::table('access.staff_users')->where('id', $staffId)->value('password');

        $response = $browser->post('/admin/account/password', [
            'current_password' => Fx::STAFF_PASSWORD,
            'password' => 'a considerably longer password',
        ]);

        $response->assertRedirect();

        expect(AdminBrowser::flashed($response, 'status'))
            ->toBe('Your password was changed. Every other session was signed out.')
            ->and((string) DB::table('access.staff_users')->where('id', $staffId)->value('password'))
            ->not->toBe($before);

        // This session survives its own change; every other one does not.
        $browser->get('/admin/account')->assertOk();
    });

    it('refuses a wrong current password', function () {
        [$browser, $staffId] = accountOrdinary();
        $before = (string) DB::table('access.staff_users')->where('id', $staffId)->value('password');

        $response = $browser->post('/admin/account/password', [
            'current_password' => 'not the right password',
            'password' => 'a considerably longer password',
        ]);

        expect(AdminBrowser::formError($response))->not->toBeNull()
            ->and((string) DB::table('access.staff_users')->where('id', $staffId)->value('password'))->toBe($before);
    });
});

describe('B4 - the notifications', function () {
    it('saves one switch as it is flipped, and leaves the other topics alone', function () {
        [$browser, $staffId] = accountOrdinary();

        $response = $browser->post('/admin/account/notifications', [
            'topic' => 'LOW_STOCK',
            'email' => true,
            'panel' => false,
        ]);

        $response->assertRedirect('/admin/account?tab=notifications');
        expect(AdminBrowser::flashed($response, 'status'))->toBe('Saved.');

        $browser->get('/admin/account')->assertInertia(function (AssertableInertia $inertia) {
            /** @var list<array{topic: string, email: bool, panel: bool}> $topics */
            $topics = $inertia->toArray()['props']['notifications'];
            $byTopic = array_column($topics, null, 'topic');

            expect($byTopic['LOW_STOCK']['email'])->toBeTrue()
                ->and($byTopic['LOW_STOCK']['panel'])->toBeFalse()
                // Topics not sent keep their toggles.
                ->and($byTopic['NEW_ORDERS']['email'])->toBeFalse()
                ->and($byTopic['NEW_ORDERS']['panel'])->toBeTrue();
        });

        expect(DB::table('access.staff_notification_preferences')
            ->where('staff_user_id', $staffId)->where('topic', 'LOW_STOCK')->value('email'))->toBeTrue();
    });

    it('refuses a topic that is not one', function () {
        [$browser] = accountOrdinary();

        $response = $browser->post('/admin/account/notifications', [
            'topic' => 'WHATEVER',
            'email' => true,
            'panel' => true,
        ]);

        $response->assertRedirect();
        expect(AdminBrowser::formError($response))->not->toBeNull();
    });
});

describe('the routes themselves', function () {
    it('gives the account page to the admin group and to no other', function () {
        [$browser] = accountOrdinary();

        $browser->get('/admin/account')->assertInertia(function (AssertableInertia $inertia) {
            /** @var array<string, mixed> $routes */
            $routes = $inertia->toArray()['props']['routes']['routes'];

            expect($routes)->toHaveKey('access.staff.account.page')
                ->and($routes)->toHaveKey('access.staff.account.notifications')
                ->and($routes)->not->toHaveKey('storefront.account.register');
        });
    });

    it('still tells the panel who is looking, on this screen as on any other', function () {
        [$browser] = accountOrdinary();

        $browser->get('/admin/account')->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('viewer.name', 'Staff Member')
            ->where('viewer.isSuperAdmin', false)
            ->where('theme.mode', 'light')
            ->where('store.available', fn (Collection $stores): bool => $stores->count() === 1)
        );
    });
});
