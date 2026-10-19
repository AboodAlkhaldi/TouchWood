<?php

declare(strict_types=1);

use Illuminate\Contracts\Cookie\QueueingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\GuestIdentity;

/*
| The guest this browser is (access.md amendment 74, §1.7): read from the storefront's cookie, created
| only when something asks for it — Sales, with a guest's first cart line — its cookie sent with the
| answer for a year, and the same id for the rest of the request.
*/

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
