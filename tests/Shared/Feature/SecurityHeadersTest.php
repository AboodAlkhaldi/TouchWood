<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\PlatformPermissions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| Production-safe defaults (handoff §15.4; audit run-2 job F, owner 2026-10-29): the headers every
| page carries, no browser copy of a page with somebody's data on it, and cookies that need HTTPS in
| production.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();

    Route::prefix('{store}/{locale}')->middleware(StorefrontArea::MIDDLEWARE)->get('headers-probe', static fn (): string => 'ok');
});

/**
 * @param  array<string, string>  $headers
 */
function assertSecurityHeaders(array $headers): void
{
    expect($headers['content-security-policy'] ?? null)->toBe("frame-ancestors 'self'")
        ->and($headers['x-content-type-options'] ?? null)->toBe('nosniff')
        ->and($headers['referrer-policy'] ?? null)->toBe('same-origin');
}

/**
 * @return array<string, string>
 */
function headersOf(mixed $response): array
{
    return array_map(fn (array $values): string => (string) ($values[0] ?? ''), $response->headers->all());
}

it('sends the security headers on a guest\'s shop page, and lets the browser keep it', function () {
    $headers = headersOf((new AdminBrowser)->get('/sa/en/headers-probe')->assertOk());

    assertSecurityHeaders($headers);
    expect($headers['cache-control'])->not->toContain('no-store');
});

it('keeps no copy of a shop page a signed-in customer sees', function () {
    Fx::customer('sara@example.test', 'sa', 'individual');
    $browser = new AdminBrowser('10.13.0.'.random_int(20, 250));
    $browser->post('/sa/en/account/sign-in', ['email' => 'sara@example.test', 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    $headers = headersOf($browser->get('/sa/en/headers-probe')->assertOk());

    assertSecurityHeaders($headers);
    expect($headers['cache-control'])->toContain('no-store')->toContain('private');
});

it('keeps no copy of a shop page a staff member views from the panel, and lets the browser keep it again once the view ends', function () {
    $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'en']);
    $browser = new AdminBrowser('10.13.0.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    $browser->post('/admin/staff-view', ['store' => 'sa'])->assertRedirect('/sa/en');
    expect(headersOf($browser->get('/sa/en/headers-probe')->assertOk())['cache-control'])->toContain('no-store');

    $browser->post('/staff-view/leave', ['store' => 'sa'])->assertRedirect('/sa/en');
    expect(headersOf($browser->get('/sa/en/headers-probe')->assertOk())['cache-control'])->not->toContain('no-store');
});

it('keeps no copy of an admin page, the sign-in screen included', function () {
    $headers = headersOf((new AdminBrowser)->get('/admin/sign-in')->assertOk());

    assertSecurityHeaders($headers);
    expect($headers['cache-control'])->toContain('no-store')->toContain('private');
});

/*
| Whether a browser has the crypto depends on the address, so these say which: a relative one takes the host of the
| developer's APP_URL.
*/
const HISTORY_HOST = 'http://localhost';

/**
 * What the page says about the browser's history.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, bool>
 */
function historyOf(TestResponse $response): array
{
    $found = [];
    $response->assertInertia(function (AssertableInertia $page) use (&$found): void {
        $found = array_intersect_key($page->toArray(), ['encryptHistory' => true, 'clearHistory' => true]);
    });

    return $found;
}

function signedInCustomer(): AdminBrowser
{
    Fx::customer('sara@example.test', 'sa', 'individual');
    $browser = new AdminBrowser('10.13.0.'.random_int(20, 250));
    $browser->post(HISTORY_HOST.'/sa/en/account/sign-in', ['email' => 'sara@example.test', 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

/**
 * @param  list<string>  $stores
 */
function signedInStaff(array $stores = ['sa']): AdminBrowser
{
    $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], $stores);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'en']);
    $browser = new AdminBrowser('10.13.0.'.random_int(20, 250));
    $browser->post(HISTORY_HOST.'/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post(HISTORY_HOST.'/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

it('keeps a signed-in customer\'s shop pages encrypted in the history, and a guest\'s plain with the key thrown away', function () {
    expect(historyOf((new AdminBrowser)->get(HISTORY_HOST.'/sa/en')))->toBe(['clearHistory' => true]);

    $browser = signedInCustomer();
    expect(historyOf($browser->get(HISTORY_HOST.'/sa/en')))->toBe(['encryptHistory' => true]);

    // The switch does not outlive the request: the next one, as a guest, in this same process.
    expect(historyOf((new AdminBrowser)->get(HISTORY_HOST.'/sa/en')))->toBe(['clearHistory' => true]);

    $browser->post(HISTORY_HOST.'/sa/en/account/sign-out')->assertRedirect('/sa/en');
    expect(historyOf($browser->get(HISTORY_HOST.'/sa/en')))->toBe(['clearHistory' => true]);
});

it('throws the key away on the first page after a customer\'s session ended with no sign-out', function () {
    $browser = signedInCustomer();
    expect(historyOf($browser->get(HISTORY_HOST.'/sa/en')))->toBe(['encryptHistory' => true]);

    DB::table('sessions')->delete();

    expect(historyOf($browser->get(HISTORY_HOST.'/sa/en')))->toBe(['clearHistory' => true]);
});

it('keeps a panel page encrypted in the history, and throws the key away on the sign-in screen', function () {
    expect(historyOf((new AdminBrowser)->get(HISTORY_HOST.'/admin/sign-in')))->toBe(['clearHistory' => true]);

    $browser = signedInStaff();
    expect(historyOf($browser->get(HISTORY_HOST.'/admin')))->toBe(['encryptHistory' => true]);

    DB::table('access.admin_sessions')->delete();

    $browser->get(HISTORY_HOST.'/admin')->assertRedirect('/admin/sign-in');
    expect(historyOf($browser->get(HISTORY_HOST.'/admin/sign-in')))->toBe(['clearHistory' => true]);
});

it('keeps a staff view of the shop encrypted in the history, until the view ends', function () {
    $browser = signedInStaff();
    $browser->post(HISTORY_HOST.'/admin/staff-view', ['store' => 'sa'])->assertRedirect('/sa/en');
    expect(historyOf($browser->get(HISTORY_HOST.'/sa/en')))->toBe(['encryptHistory' => true]);

    $browser->post(HISTORY_HOST.'/staff-view/leave', ['store' => 'sa'])->assertRedirect('/sa/en');
    expect(historyOf($browser->get(HISTORY_HOST.'/sa/en')))->toBe(['clearHistory' => true]);
});

it('leaves the session alone on a guest\'s post: only a page drawn for nobody throws the key away', function () {
    $browser = new AdminBrowser('10.13.0.'.random_int(20, 250));
    Fx::customer('sara@example.test', 'sa', 'individual');
    $browser->post(HISTORY_HOST.'/sa/en/account/sign-in', ['email' => 'sara@example.test', 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    expect(historyOf($browser->get(HISTORY_HOST.'/sa/en')))->toBe(['encryptHistory' => true]);
});

it('asks for encryption only of a browser that has it', function (string $url, bool $encrypted) {
    $browser = signedInCustomer();

    expect(historyOf($browser->get($url)))->toBe($encrypted ? ['encryptHistory' => true] : []);
})->with([
    'https' => ['https://shop.example.test/sa/en', true],
    'localhost' => ['http://localhost/sa/en', true],
    '127.0.0.1' => ['http://127.0.0.1/sa/en', true],
    'ipv6 loopback' => ['http://[::1]/sa/en', true],
    'a .localhost name' => ['http://touchwood.localhost/sa/en', true],
    'plain http on a name' => ['http://shop.example.test/sa/en', false],
    'plain http on a network address' => ['http://192.168.1.20/sa/en', false],
    'a name only ending like it' => ['http://notlocalhost/sa/en', false],
]);

it('does not take a Host header for a developer\'s machine in production', function (string $url, bool $encrypted) {
    $browser = signedInCustomer();
    app()['env'] = 'production';

    try {
        expect(historyOf($browser->get($url)))->toBe($encrypted ? ['encryptHistory' => true] : []);
    } finally {
        app()['env'] = 'testing';
    }
})->with([
    'localhost' => ['http://localhost/sa/en', false],
    '127.0.0.1' => ['http://127.0.0.1/sa/en', false],
    'ipv6 loopback' => ['http://[::1]/sa/en', false],
    'a .localhost name' => ['http://touchwood.localhost/sa/en', false],
    'https' => ['https://shop.example.test/sa/en', true],
]);

it('throws the key away on the country page, where leaving a view of a switched-off store lands', function () {
    $browser = signedInStaff(['ae']);
    $browser->post(HISTORY_HOST.'/admin/staff-view', ['store' => 'ae'])->assertRedirect('/ae/en');
    expect(historyOf($browser->get(HISTORY_HOST.'/ae/en')))->toBe(['encryptHistory' => true]);

    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));

    $browser->post(HISTORY_HOST.'/staff-view/leave', ['store' => 'ae'])->assertRedirect('/');
    expect(historyOf($browser->get(HISTORY_HOST.'/')))->toBe(['clearHistory' => true]);
});

it('sends the security headers on a response outside both areas', function () {
    assertSecurityHeaders(headersOf(get('/up')->assertOk()));
});

/**
 * Sets (or, given null, removes) an environment variable everywhere env() looks, and returns how it was.
 *
 * @return array{0: mixed, 1: mixed, 2: string|false}
 */
function swapEnv(string $name, ?string $value): array
{
    $was = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
    restoreEnv($name, [$value, $value, $value ?? false]);

    return $was;
}

/**
 * @param  array{0: mixed, 1: mixed, 2: string|false}  $was
 */
function restoreEnv(string $name, array $was): void
{
    [$server, $env, $put] = $was;
    if ($server === null) {
        unset($_SERVER[$name]);
    } else {
        $_SERVER[$name] = $server;
    }
    if ($env === null) {
        unset($_ENV[$name]);
    } else {
        $_ENV[$name] = $env;
    }
    putenv($put === false ? $name : "{$name}={$put}");
}

it('makes cookies HTTPS-only by default in production, and only there', function (string $appEnv, ?bool $secure) {
    $appWas = swapEnv('APP_ENV', $appEnv);
    $secureWas = swapEnv('SESSION_SECURE_COOKIE', null);

    try {
        expect((require config_path('session.php'))['secure'])->toBe($secure);
    } finally {
        restoreEnv('APP_ENV', $appWas);
        restoreEnv('SESSION_SECURE_COOKIE', $secureWas);
    }
})->with([
    'production' => ['production', true],
    'local' => ['local', null],
    'testing' => ['testing', null],
]);
