<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Application\Routing\InMemoryReservedPaths;
use Modules\Platform\Domain\Exception\StoreNotFound;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;
use function Pest\Laravel\withCookie;
use function Pest\Laravel\withCookies;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

describe('a store and its language', function () {
    it('resolves the store from the first segment and the language from the second', function () {
        get('/sa/ar')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('السعودية')
            ->assertSee("\u{20C1}");

        get('/sa/en')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('Saudi Arabia');
    });

    it('shows the letters for a currency that has no sign, in the page language', function () {
        get('/eg/ar')->assertOk()->assertSee('ج.م');
        get('/eg/en')->assertOk()->assertSee('EGP');
    });

    it('remembers the store and the language in cookies', function () {
        get('/ae/en')->assertCookie('tw_store', 'ae')->assertCookie('tw_locale', 'en');
    });

    it('keeps both cookies for one year (owner\'s decision)', function (string $name) {
        $expiresIn = (get('/ae/en')->getCookie($name, false)?->getExpiresTime() ?? 0) - time();

        expect($expiresIn)->toBeGreaterThan(365 * 24 * 3600 - 60)
            ->and($expiresIn)->toBeLessThanOrEqual(365 * 24 * 3600);
    })->with(['tw_store', 'tw_locale']);

    it('answers 404 for an unknown store or an unsupported language', function (string $path) {
        get($path)->assertNotFound();
    })->with(['/xx/ar', '/SA/ar', '/toolongcode/ar', '/sa/fr', '/sa/AR', '/sa/arabic']);

    it('answers a JSON request for an unknown store with a problem document', function () {
        getJson('/xx/ar')->assertNotFound()->assertJsonPath('type', 'http.404');
    });

    it('fills in the store and the language in every link it generates', function () {
        Route::middleware('web')->prefix('{store}/{locale}')->middleware('store')
            ->get('/_probe/link', fn () => route('storefront.home'));

        get('/ae/en/_probe/link')->assertOk()->assertSee('/ae/en');
    });

    it('answers errors in the page language', function () {
        Route::middleware('web')->prefix('{store}/{locale}')->middleware('store')
            ->get('/_probe/error', fn () => throw new StoreNotFound('zz'));

        $arabic = (string) getJson('/sa/ar/_probe/error')->assertNotFound()->json('title');
        $english = (string) getJson('/sa/en/_probe/error')->assertNotFound()->json('title');

        expect($english)->not->toBe($arabic)
            ->and($english)->toMatch('/[A-Za-z]/');
    });
});

describe('a store without a language', function () {
    it('sends a new visitor on to Arabic', function () {
        get('/sa')->assertRedirect('/sa/ar');
    });

    it('sends a returning visitor on to the language they used last', function () {
        withCookie('tw_locale', 'en')->get('/sa')->assertRedirect('/sa/en');
    });

    it('ignores a remembered language that is not supported', function () {
        withCookie('tw_locale', 'fr')->get('/sa')->assertRedirect('/sa/ar');
    });

    it('keeps the query string, so ad and campaign parameters survive the redirect', function () {
        get('/sa?utm_source=google&gclid=abc123')->assertRedirect('/sa/ar?utm_source=google&gclid=abc123');
    });

    it('drops unnamed query parameters instead of failing', function (string $query) {
        get("/sa?{$query}")->assertRedirect('/sa/ar?utm_source=google');
    })->with(['0=x&utm_source=google', '0[]=x&utm_source=google']);

    it('answers 404 for a store that does not exist', function () {
        get('/xx')->assertNotFound();
    });
});

describe('the country page at brand.com/', function () {
    it('sends a visitor back to their store and language', function () {
        withCookies(['tw_store' => 'eg', 'tw_locale' => 'en'])->get('/')->assertRedirect('/eg/en');
    });

    it('uses Arabic for a remembered store when no language is remembered', function () {
        withCookie('tw_store', 'eg')->get('/')->assertRedirect('/eg/ar');
    });

    it('keeps the query string when sending a visitor back to their store', function () {
        withCookie('tw_store', 'eg')->get('/?utm_campaign=eid')->assertRedirect('/eg/ar?utm_campaign=eid');
    });

    it('shows the country page in Arabic to a new visitor, linking to the Arabic stores', function () {
        get('/')
            ->assertOk()
            ->assertSeeInOrder(['السعودية', 'مصر', 'الإمارات'])
            ->assertSee('/sa/ar');
    });

    it('shows the country page in the remembered language', function () {
        withCookie('tw_locale', 'en')->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Saudi Arabia', 'Egypt', 'United Arab Emirates'])
            ->assertSee('/sa/en');
    });

    it('shows the country page when the remembered store no longer exists', function () {
        withCookie('tw_store', 'zz')->get('/')->assertOk()->assertSee('اختر دولتك');
    });
});

describe('reserved paths', function () {
    it('does not treat reserved paths as store codes', function () {
        get('/up')->assertOk();
    });

    it('keeps reserved paths out of every storefront route, not only at the top level', function (string $path, int $status) {
        Route::middleware('web')->prefix('{store}/{locale}')->middleware('store')->get('/_probe/{slug}', fn () => 'storefront');

        get($path)->assertStatus($status);
    })->with([
        'a store' => ['/sa/ar/_probe/hinges', 200],
        'admin below the top level' => ['/admin/ar/_probe/login', 404],
        'api below the top level' => ['/api/en/_probe/orders', 404],
    ]);

    it('keeps a store whose code starts like a reserved word reachable', function () {
        // "upx" is a valid code: only the exact reserved segments are excluded.
        $pattern = app(InMemoryReservedPaths::class)->routePattern();

        expect(preg_match('#^'.$pattern.'$#', 'upx'))->toBe(1)
            ->and(preg_match('#^'.$pattern.'$#', 'up'))->toBe(0);
    });

    it('builds the {store} pattern at boot from every path the modules reserved, then locks the list', function () {
        $paths = app(InMemoryReservedPaths::class);

        // Platform reserves its own paths while registering; they must be in the live pattern.
        expect($paths->all())->toContain('admin', 'api', 'build', 'storage', 'up')
            ->and(app('router')->getPatterns()['store'] ?? null)->toBe($paths->routePattern())
            ->and(app('router')->getPatterns()['locale'] ?? null)->toBe('ar|en')
            ->and(fn () => $paths->reserve('access', 'login'))->toThrow(LogicException::class, 'register()');
    });
});

it('resolves the store from the cache alone once warm: two tiny cache reads, never the store tables', function () {
    // The cache lives in PostgreSQL (owner, 2026-09-18), so a warm request reads the version and
    // the snapshot from the cache table. It must never fall back to loading stores and currencies.
    get('/sa/ar')->assertOk();

    DB::enableQueryLog();
    get('/sa/ar')->assertOk();
    $queries = array_column(DB::getQueryLog(), 'query');

    expect($queries)->toHaveCount(2);

    foreach ($queries as $query) {
        expect($query)->toContain('"cache"');
        expect($query)->not->toContain('platform');
    }
});
