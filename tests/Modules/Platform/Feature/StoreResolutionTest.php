<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Application\Routing\InMemoryReservedPaths;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;
use function Pest\Laravel\withCookie;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    app()->setLocale('ar');
});

it('resolves a store from the first path segment', function () {
    get('/sa')
        ->assertOk()
        ->assertSee('السعودية')
        ->assertSee("\u{20C1}");
});

it('shows the letters for a currency that has no sign', function () {
    get('/eg')->assertOk()->assertSee('ج.م');
});

it('remembers the store in a cookie', function () {
    get('/ae')->assertCookie('tw_store', 'ae');
});

it('answers 404 for a store that does not exist', function (string $path) {
    get($path)->assertNotFound();
})->with(['/xx', '/SA', '/toolongcode']);

it('answers a JSON request for an unknown store with a problem document', function () {
    getJson('/xx')->assertNotFound()->assertJsonPath('type', 'http.404');
});

it('does not treat reserved paths as store codes', function () {
    get('/up')->assertOk();
});

it('keeps reserved paths out of every storefront route, not only at the top level', function (string $path, int $status) {
    Route::middleware('web')->prefix('{store}')->middleware('store')->get('/_probe/{slug}', fn () => 'storefront');

    get($path)->assertStatus($status);
})->with([
    'a store' => ['/sa/_probe/hinges', 200],
    'admin below the top level' => ['/admin/_probe/login', 404],
    'api below the top level' => ['/api/_probe/orders', 404],
]);

it('keeps a store whose code starts like a reserved word reachable', function () {
    // "upx" is a valid code: only the exact reserved segments are excluded.
    $pattern = app(InMemoryReservedPaths::class)->routePattern();

    expect(preg_match('#^'.$pattern.'$#', 'upx'))->toBe(1)
        ->and(preg_match('#^'.$pattern.'$#', 'up'))->toBe(0);
});

it('sends a visitor back to the store in their cookie', function () {
    withCookie('tw_store', 'eg')->get('/')->assertRedirect('/eg');
});

it('shows the country page to a visitor with no remembered store', function () {
    get('/')
        ->assertOk()
        ->assertSeeInOrder(['السعودية', 'مصر', 'الإمارات']);
});

it('shows the country page when the remembered store no longer exists', function () {
    withCookie('tw_store', 'zz')->get('/')->assertOk()->assertSee('اختر دولتك');
});

it('shows the country page in English', function () {
    app()->setLocale('en');

    get('/')->assertOk()->assertSeeInOrder(['Saudi Arabia', 'Egypt', 'United Arab Emirates']);
});

it('resolves the store from the cache alone once warm: two tiny cache reads, never the store tables', function () {
    // The cache lives in PostgreSQL (owner, 2026-09-18), so a warm request reads the version and
    // the snapshot from the cache table. It must never fall back to loading stores and currencies.
    get('/sa')->assertOk();

    DB::enableQueryLog();
    get('/sa')->assertOk();
    $queries = array_column(DB::getQueryLog(), 'query');

    expect($queries)->toHaveCount(2);

    foreach ($queries as $query) {
        expect($query)->toContain('"cache"');
        expect($query)->not->toContain('platform');
    }
});

it('builds the {store} pattern at boot from every path the modules reserved, then locks the list', function () {
    $paths = app(InMemoryReservedPaths::class);

    // Platform reserves its own paths while registering; they must be in the live pattern.
    expect($paths->all())->toContain('admin', 'api', 'build', 'storage', 'up')
        ->and(app('router')->getPatterns()['store'] ?? null)->toBe($paths->routePattern())
        ->and(fn () => $paths->reserve('access', 'login'))->toThrow(LogicException::class, 'register()');
});
