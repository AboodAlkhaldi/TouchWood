<?php

declare(strict_types=1);

use Illuminate\Contracts\Cookie\QueueingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\GuestIdentity;

/*
| The guest this browser is (access.md amendment 74, §1.7): read from the storefront's cookie, created
| only when something asks for it — Sales, with a guest's first cart line — its cookie sent with the
| answer for a year, and the same id for the rest of the request.
*/

use function Pest\Laravel\get;
use function Pest\Laravel\withCookie;
use function Pest\Laravel\withUnencryptedCookie;

uses(RefreshDatabase::class);

/**
 * @param  array<string, string>  $cookies
 */
function guestIdentityRequest(array $cookies = []): GuestIdentity
{
    app()->instance('request', Request::create('/sa/en', 'GET', [], $cookies));
    app()->forgetScopedInstances();

    return app(GuestIdentity::class);
}

it('reads nothing from a browser with no cookie, and creates nothing until asked', function () {
    $guests = guestIdentityRequest();

    expect($guests->current())->toBeNull()
        ->and(app(QueueingFactory::class)->getQueuedCookies())->toBe([]);
});

it('creates the guest when asked, sends its cookie for a year, and keeps it for the rest of the request', function () {
    $guests = guestIdentityRequest();

    $id = $guests->ensure();
    $cookie = app(QueueingFactory::class)->queued((string) config('access.storefront.guest_cookie'));

    expect(Str::isUlid($id))->toBeTrue()
        ->and($id)->toBe(strtolower($id))
        ->and($guests->current())->toBe($id)
        ->and($guests->ensure())->toBe($id)
        ->and($cookie?->getValue())->toBe($id)
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getExpiresTime())->toBeGreaterThan(now()->addDays(364)->getTimestamp())
        ->and(app(GuestIdentity::class))->toBe($guests);
});

it('reads the guest the cookie names, and creates no other', function () {
    $known = strtolower((string) Str::ulid());
    $guests = guestIdentityRequest([(string) config('access.storefront.guest_cookie') => strtoupper($known)]);

    expect($guests->current())->toBe($known)
        ->and($guests->ensure())->toBe($known)
        ->and(app(QueueingFactory::class)->getQueuedCookies())->toBe([]);
});

it('treats a forged cookie as no guest at all', function () {
    $guests = guestIdentityRequest([(string) config('access.storefront.guest_cookie') => 'not-an-id']);

    expect($guests->current())->toBeNull()
        ->and($guests->ensure())->not->toBe('not-an-id');
});

it('sends the guest cookie encrypted through the web stack, and reads it back on the next request', function () {
    Route::middleware('web')->get('/_test/guest-identity', static fn () => response()->json(['guest' => app(GuestIdentity::class)->ensure()]));
    Route::middleware('web')->get('/_test/guest-identity/read', static fn () => response()->json(['guest' => app(GuestIdentity::class)->current()]));
    $name = (string) config('access.storefront.guest_cookie');

    $first = get('/_test/guest-identity');
    $id = (string) $first->json('guest');
    $cookie = $first->getCookie($name, false);

    expect($cookie)->not->toBeNull()
        ->and($cookie?->getValue())->not->toBe($id)
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe('lax')
        ->and($cookie?->getPath())->toBe('/')
        ->and(abs(($cookie?->getExpiresTime() ?? 0) - now()->addDays(365)->getTimestamp()))->toBeLessThan(5);

    app()->forgetScopedInstances();
    withCookie($name, $id)->get('/_test/guest-identity/read')->assertJson(['guest' => $id]);

    app()->forgetScopedInstances();
    withUnencryptedCookie($name, $id)->get('/_test/guest-identity/read')->assertJson(['guest' => null]);
});

it('keeps an id made for one request from the next one', function () {
    $guests = guestIdentityRequest();
    $made = $guests->ensure();

    app()->instance('request', Request::create('/sa/en', 'GET'));

    expect($guests->current())->toBeNull()
        ->and($guests->ensure())->not->toBe($made);
});
