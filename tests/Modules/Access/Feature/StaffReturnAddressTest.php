<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Command\ResetSuperAdminPhone\ResetSuperAdminPhone;
use Modules\Access\Application\Command\ResetSuperAdminPhone\ResetSuperAdminPhoneHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Presentation\Http\Middleware\StaffReturnAddress;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\AuthenticatorFixtures as App;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Amendment 95 (owner, 2026-11-02): a signed-out staff member who opens a panel address is taken back to it after
| signing in, so an alert's link works. Every way through signing in ends at the panel's home, which sends them on, once.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

const STAFF_RETURN_PAGE = '/admin/staff?search=sara&unknown=kept';

/** A staff member who may open STAFF_RETURN_PAGE. */
function staffReturnPerson(bool $superAdmin = false): string
{
    return $superAdmin ? Fx::staff(superAdmin: true) : Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa'], RoleLevel::Admin);
}

/** @return TestResponse<Response> */
function staffReturnPassword(AdminBrowser $browser, string $staffId): TestResponse
{
    return $browser->post('/admin/sign-in', ['email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'), 'password' => Fx::STAFF_PASSWORD]);
}

/** The browser, signed out, opening the page: to the sign-in. */
function staffReturnVisit(AdminBrowser $browser, string $address = STAFF_RETURN_PAGE): void
{
    $browser->get($address)->assertRedirect('/admin/sign-in');
}

function staffReturnCode(): string
{
    return RecordingSecurityMessages::installed()->lastCode();
}

/** Arriving at the panel's home goes on to the page, once: the next visit to home stays there. */
function staffReturnArrives(AdminBrowser $browser, string $address = STAFF_RETURN_PAGE): void
{
    $browser->get('/admin')->assertRedirect($address);
    $browser->get($address)->assertOk();
    $browser->get('/admin')->assertOk();
}

describe('every way through signing in', function () {
    it('survives the password and the SMS code, with the query string', function () {
        $browser = new AdminBrowser;
        staffReturnVisit($browser);

        staffReturnPassword($browser, staffReturnPerson())->assertRedirect('/admin/sign-in/code');
        $browser->get('/admin/sign-in/code')->assertOk();
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');

        staffReturnArrives($browser);
    });

    it('survives a wrong password and a wrong code on the way', function () {
        $staffId = staffReturnPerson();
        $browser = new AdminBrowser;
        staffReturnVisit($browser);

        $browser->post('/admin/sign-in', ['email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'), 'password' => 'not the password'])->assertRedirect();
        staffReturnPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
        $browser->post('/admin/sign-in/code', ['code' => '000000'])->assertRedirect();
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');

        staffReturnArrives($browser);
    });

    it('survives the app\'s code', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = staffReturnPerson();
        $secret = App::giveApp($staffId)['secret'];
        cache()->flush();
        $browser = new AdminBrowser;
        staffReturnVisit($browser);

        staffReturnPassword($browser, $staffId)->assertRedirect('/admin/sign-in/app');
        $browser->post('/admin/sign-in/app', ['code' => App::code($secret)])->assertRedirect('/admin');

        staffReturnArrives($browser);
    });

    it('survives a recovery code in place of the app\'s', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = staffReturnPerson();
        $codes = App::giveApp($staffId)['codes'];
        cache()->flush();
        $browser = new AdminBrowser;
        staffReturnVisit($browser);

        staffReturnPassword($browser, $staffId)->assertRedirect('/admin/sign-in/app');
        $browser->post('/admin/sign-in/app', ['code' => $codes[0], 'recovery' => true])->assertRedirect('/admin');

        staffReturnArrives($browser);
    });

    it('survives either method when both boxes are ticked: the one the person chose', function (bool $inUse, string $step) {
        App::methods(sms: true, authenticator: true);
        $staffId = staffReturnPerson();
        $secret = App::giveApp($staffId, $inUse)['secret'];
        cache()->flush();
        $browser = new AdminBrowser;
        staffReturnVisit($browser);

        staffReturnPassword($browser, $staffId)->assertRedirect("/admin/sign-in/{$step}");
        $browser->post("/admin/sign-in/{$step}", ['code' => $step === 'app' ? App::code($secret) : staffReturnCode()])->assertRedirect('/admin');

        staffReturnArrives($browser);
    })->with([
        'chose the app' => [true, 'app'],
        'chose SMS' => [false, 'code'],
    ]);

    it('survives a forced new phone: a Super Admin whose phone was reset', function () {
        $superAdmin = staffReturnPerson(superAdmin: true);
        app(ResetSuperAdminPhoneHandler::class)->handle(new ResetSuperAdminPhone((string) DB::table('access.staff_users')->where('id', $superAdmin)->value('email')));
        cache()->flush();
        $browser = new AdminBrowser;
        staffReturnVisit($browser);

        staffReturnPassword($browser, $superAdmin)->assertRedirect('/admin/sign-in/phone');
        $browser->post('/admin/sign-in/phone', ['phone' => '+966507777777'])->assertRedirect('/admin/sign-in/code');
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');

        staffReturnArrives($browser);
    });

    it('survives a forced authenticator setup and its recovery codes', function () {
        $staffId = staffReturnPerson();
        App::methods(sms: true, authenticator: false);
        $browser = new AdminBrowser;
        staffReturnPassword($browser, $staffId);
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode(), 'trust_browser' => true])->assertRedirect('/admin');
        $browser->post('/admin/sign-out');

        App::methods(sms: false, authenticator: true);
        staffReturnVisit($browser);

        // The trusted browser skips the code, and the app is required with none set up.
        staffReturnPassword($browser, $staffId)->assertRedirect('/admin');
        $browser->get('/admin')->assertRedirect('/admin/authenticator/setup');
        $browser->get(STAFF_RETURN_PAGE)->assertRedirect('/admin/authenticator/setup');
        $browser->post('/admin/authenticator/setup', ['current_password' => Fx::STAFF_PASSWORD])->assertRedirect('/admin/authenticator/setup');
        $key = '';
        $browser->get('/admin/authenticator/setup')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$key): void {
            $key = $page->toArray()['props']['manualKey'];
        });
        $browser->post('/admin/authenticator/setup/code', ['code' => App::code(str_replace(' ', '', (string) $key))])->assertRedirect('/admin/authenticator/recovery-codes');
        $browser->get('/admin/authenticator/recovery-codes')->assertOk();

        staffReturnArrives($browser);
    });

    it('survives a trusted browser, which skips the code', function () {
        $staffId = staffReturnPerson();
        $browser = new AdminBrowser;
        staffReturnPassword($browser, $staffId);
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode(), 'trust_browser' => true])->assertRedirect('/admin');
        $browser->post('/admin/sign-out');
        staffReturnVisit($browser);

        staffReturnPassword($browser, $staffId)->assertRedirect('/admin');

        staffReturnArrives($browser);
    });

    it('sends an Inertia visit on by a full page load, so a page or a file both arrive', function () {
        $browser = new AdminBrowser;
        staffReturnVisit($browser);
        staffReturnPassword($browser, staffReturnPerson())->assertRedirect('/admin/sign-in/code');
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');

        $response = $browser->get('/admin', ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => (string) app(HandleInertiaRequests::class)->version(request())]);

        expect($response->getStatusCode())->toBe(409)
            ->and($response->headers->get('X-Inertia-Location'))->toBe(STAFF_RETURN_PAGE);
        $browser->get('/admin')->assertOk();
    });
});

describe('what it remembers', function () {
    it('never remembers a request that is not a GET, or one asking for JSON', function () {
        $staffId = staffReturnPerson();
        $browser = new AdminBrowser;

        $browser->post('/admin/account/sign-in-method', ['method' => 'SMS'])->assertRedirect('/admin/sign-in');
        $browser->get('/admin/staff', ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();

        staffReturnPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');
        $browser->get('/admin')->assertOk();
    });

    it('keeps the latest page, and forgets it when home itself is asked for', function () {
        $staffId = staffReturnPerson();
        $browser = new AdminBrowser;
        staffReturnVisit($browser, '/admin/account');
        staffReturnVisit($browser);
        staffReturnPassword($browser, $staffId);
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');
        staffReturnArrives($browser);

        $browser->post('/admin/sign-out');
        staffReturnVisit($browser);
        staffReturnVisit($browser, '/admin');
        staffReturnPassword($browser, $staffId);
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');
        $browser->get('/admin')->assertOk();
    });

    it('forgets it on signing out: it belongs to the one session', function () {
        $staffId = staffReturnPerson();
        $browser = new AdminBrowser;
        staffReturnVisit($browser);
        staffReturnPassword($browser, $staffId);
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');
        $browser->post('/admin/sign-out');

        staffReturnPassword($browser, $staffId);
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');
        $browser->get('/admin')->assertOk();

        // Another browser never had it.
        $other = new AdminBrowser('10.9.8.77');
        staffReturnPassword($other, $staffId);
        $other->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');
        $other->get('/admin')->assertOk();
    });

    it('takes them to a page they may not open, which refuses as it does today', function () {
        $staffId = Fx::staffWith(['ops.report.view'], ['sa']);
        $browser = new AdminBrowser;
        staffReturnVisit($browser);
        staffReturnPassword($browser, $staffId);
        $browser->post('/admin/sign-in/code', ['code' => staffReturnCode()])->assertRedirect('/admin');

        $browser->get('/admin')->assertRedirect(STAFF_RETURN_PAGE);
        $browser->get(STAFF_RETURN_PAGE)->assertForbidden();
        $browser->get('/admin')->assertOk();
    });

    it('takes only a page of the panel', function (string $address, bool $safe) {
        expect(StaffReturnAddress::isSafe($address))->toBe($safe);
    })->with([
        'a page' => ['/admin/orders/01jbxqz2y8k4n6p9r3t5v7w1xa', true],
        'with its query' => ['/admin/payments/gateway?store=sa', true],
        'a query that holds an address' => ['/admin/staff?next=https://example.test/', true],
        'home' => ['/admin', false],
        'home with a query' => ['/admin?x=1', false],
        'a full address' => ['https://example.test/admin/staff', false],
        'another host' => ['//example.test/admin/staff', false],
        'another host, inside' => ['/admin//example.test', false],
        'a backslash' => ['/admin/\\example.test', false],
        'a tab' => ["/admin/\tstaff", false],
        'a line break' => ["/admin/staff\r\nX: y", false],
        'climbing out' => ['/admin/../sa/en', false],
        'climbing out, encoded' => ['/admin/%2e%2e/sa/en', false],
        'a slash, encoded' => ['/admin/%2f%2fexample.test', false],
        'outside the panel' => ['/sa/en/account', false],
        'a name starting the same' => ['/administrator', false],
        'the sign-in' => ['/admin/sign-in', false],
        'the sign-in\'s code' => ['/admin/sign-in/code', false],
        'the sign-out' => ['/admin/sign-out', false],
        'an invitation' => ['/admin/invitation/token', false],
        'a password reset' => ['/admin/password/reset/token', false],
        'an email change' => ['/admin/email-change/token', false],
        'too long' => ['/admin/staff?x='.str_repeat('a', 2001), false],
    ]);

    it('reads back through the same check: an unsafe address in the session is dropped', function () {
        $session = app('session.store');
        $session->put('access.staff_return', '//example.test/admin');
        $request = Request::create('/admin');
        $request->setLaravelSession($session);

        expect(StaffReturnAddress::take($request))->toBeNull()
            ->and($session->has('access.staff_return'))->toBeFalse();
    });

    it('never writes the customer side\'s own return, nor reads it', function () {
        $session = app('session.store');
        $session->put('url.intended', '/admin/staff');
        $request = Request::create(STAFF_RETURN_PAGE);
        $request->setLaravelSession($session);

        expect(StaffReturnAddress::take($request))->toBeNull();
        StaffReturnAddress::remember($request);

        expect($session->get('url.intended'))->toBe('/admin/staff')
            ->and($session->get('access.staff_return'))->toBe(STAFF_RETURN_PAGE);
    });

    it('leaves a customer signing in on the same browser where they were going', function () {
        $customer = Fx::customer('shopper@example.test');
        cache()->flush();
        $browser = new AdminBrowser;
        staffReturnVisit($browser);

        $browser->post('/sa/en/account/sign-in', ['email' => 'shopper@example.test', 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect(route('storefront.home', ['store' => 'sa', 'locale' => 'en']));

        expect($customer)->not->toBe('');
    });
});
