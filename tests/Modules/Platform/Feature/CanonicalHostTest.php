<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Presentation\Http\Middleware\CanonicalHost;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| One canonical host (content.md §1.14, §2.3 row L5; Q13 (a)): `www.` + APP_URL's host answers a permanent
| redirect to the same path and query on APP_URL's host; any other host is untouched (plan K7).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    config(['app.url' => 'https://touchwood.test']);
});

describe('the other host', function () {
    it('answers 301 to the same path and query on the canonical host, before anything else runs', function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = get('https://www.touchwood.test/sa/ar/categories/chairs?utm_source=x&page=2');
        DB::disableQueryLog();

        $response->assertStatus(301)->assertHeader('Location', 'https://touchwood.test/sa/ar/categories/chairs?utm_source=x&page=2');
        // Nothing of the site ran: no store read, no session, no query at all.
        expect(DB::getQueryLog())->toBe([]);
    });

    it('answers a read of the front page and of an address nobody answers the same way', function () {
        get('https://www.touchwood.test/')->assertStatus(301)->assertHeader('Location', 'https://touchwood.test/');
        get('https://WWW.Touchwood.test/flash-deals')->assertStatus(301)->assertHeader('Location', 'https://touchwood.test/flash-deals');
    });

    it('keeps a form\'s method and body with 308', function () {
        call('POST', 'https://www.touchwood.test/sa/en/cart/lines')->assertStatus(308)->assertHeader('Location', 'https://touchwood.test/sa/en/cart/lines');
    });

    it('keeps APP_URL\'s scheme and port, never the request\'s', function () {
        config(['app.url' => 'http://touchwood.test:8014']);

        get('https://www.touchwood.test/sa')->assertStatus(301)->assertHeader('Location', 'http://touchwood.test:8014/sa');
    });

    it('takes the canonical host without www even when APP_URL names it with www', function () {
        config(['app.url' => 'https://www.touchwood.test']);

        get('https://www.touchwood.test/sa')->assertStatus(301)->assertHeader('Location', 'https://touchwood.test/sa');
    });
});

describe('APP_URL on the www. host (PR 4 review)', function () {
    it('is refused when the middleware is registered, saying what to set', function () {
        expect(fn () => CanonicalHost::assertAppUrl('https://www.touchwood.test'))->toThrow(LogicException::class, 'set APP_URL to the address without it')
            ->and(fn () => CanonicalHost::assertAppUrl('https://WWW.touchwood.test:8443/x'))->toThrow(LogicException::class);
    });

    it('passes an APP_URL without www., and an app with no APP_URL yet (lesson 30)', function (mixed $url) {
        CanonicalHost::assertAppUrl($url);

        expect(true)->toBeTrue();
    })->with(['https://touchwood.test', 'http://localhost', 'https://staging.touchwood.test', '', null]);

    it('is checked at boot when serving HTTP, and never in the console, where config:clear must still run', function () {
        expect(fn () => CanonicalHost::assertAppUrlAtBoot(false, 'https://www.touchwood.test'))->toThrow(LogicException::class);

        CanonicalHost::assertAppUrlAtBoot(true, 'https://www.touchwood.test');

        $provider = (string) file_get_contents(base_path('src/Modules/Platform/Infrastructure/PlatformServiceProvider.php'));
        expect($provider)->toContain("CanonicalHost::assertAppUrlAtBoot(\$this->app->runningInConsole(), \$this->app->make('config')->get('app.url'));");
    });
});

describe('every other host', function () {
    it('is untouched: the canonical host itself, a staging host, another name', function (string $url) {
        expect(in_array(get($url)->getStatusCode(), [301, 308], true))->toBeFalse();
    })->with([
        'the canonical host' => 'https://touchwood.test/sa/ar',
        'a staging host' => 'https://staging.touchwood.test/sa/ar',
        'www of another host' => 'https://www.staging.touchwood.test/sa/ar',
        'a host that only ends like it' => 'https://notwww.touchwood.test/sa/ar',
    ]);

    it('is untouched when APP_URL names no host', function () {
        config(['app.url' => '']);

        expect(get('https://www.touchwood.test/sa/ar')->getStatusCode())->not->toBe(301);
    });
});
