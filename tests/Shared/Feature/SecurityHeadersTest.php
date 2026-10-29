<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Public\PlatformPermissions;
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
