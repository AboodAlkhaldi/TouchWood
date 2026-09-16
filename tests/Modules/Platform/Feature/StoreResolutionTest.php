<?php

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

it('resolves the store without touching the database once the cache is warm', function () {
    get('/sa')->assertOk();

    DB::enableQueryLog();
    get('/sa')->assertOk();

    expect(DB::getQueryLog())->toBe([]);
});
