<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\CapturedNewsletterList;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

/*
| The stop-email pages (ops.md §1.3, §1.9; owner, 2026-11-01): a link already sent always stops the email,
| even for a store switched off since. That page only: everything else of that store stays a 404, and a
| missing or bad signature reveals nothing.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    RecordingSecurityMessages::install();
    CapturedEmails::install();
    CapturedNewsletterList::install();
});

function stopEmailStoreOff(string $code = 'ae'): void
{
    DB::table('platform.stores')->where('code', $code)->update(['is_active' => false]);
    cache()->flush();
}

function stopEmailConsent(string $customerId): bool
{
    return (bool) DB::table('access.customers')->where('id', $customerId)->value('marketing_consent');
}

/** A confirmed guest on the store's list; its id. */
function stopEmailGuest(string $code, string $email): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.subscribers')->insert([
        'id' => $id, 'store_id' => Fx::storeId($code), 'email' => $email, 'locale' => 'en', 'kind' => 'GUEST',
        'customer_id' => null, 'token_hash' => null, 'created_at' => now(), 'confirmed_at' => now(),
        'brevo_email' => $email, 'brevo_locale' => 'en', 'brevo_synced_at' => now(),
    ]);

    return $id;
}

it('opens and takes a customer\'s unsubscribe for a switched-off store: consent off, in the page\'s language', function () {
    $sara = Wx::agreeing('sara@example.test', 'ae');
    $signed = URL::signedRoute('storefront.ops.unsubscribe', ['store' => 'ae', 'locale' => 'ar', 'customer' => $sara], null, false);
    stopEmailStoreOff();

    getJson($signed)->assertOk()->assertJsonPath('action', 'إلغاء الاشتراك');
    expect(stopEmailConsent($sara))->toBeTrue();

    call('POST', $signed, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertExactJson(['unsubscribed' => true]);
    expect(DB::table('access.customers')->where('id', $sara)->first())->toMatchArray(['marketing_consent' => false, 'marketing_consent_source' => 'UNSUBSCRIBE_LINK']);
});

it('opens and takes a guest\'s leave for a switched-off store: the guest off, the provider told', function () {
    $guest = stopEmailGuest('ae', 'guest@example.test');
    $signed = URL::signedRoute('storefront.ops.newsletter.leave', ['store' => 'ae', 'locale' => 'en', 'subscriber' => $guest], null, false);
    $ae = Fx::storeId('ae');
    stopEmailStoreOff();

    getJson($signed)->assertOk()->assertJsonPath('action', 'Unsubscribe');
    expect(DB::table('ops.subscribers')->count())->toBe(1);

    postJson($signed)->assertOk()->assertExactJson(['unsubscribed' => true]);
    expect(DB::table('ops.subscribers')->count())->toBe(0)
        ->and(CapturedNewsletterList::installed()->last())->toBe("remove guest@example.test {$ae} en");
});

it('sets the page\'s language and remembers nothing of a store that is off', function () {
    $sara = Wx::agreeing('sara@example.test', 'ae');
    $signed = URL::signedRoute('storefront.ops.unsubscribe', ['store' => 'ae', 'locale' => 'en', 'customer' => $sara], null, false);
    stopEmailStoreOff();

    $page = getJson($signed)->assertOk()->assertJsonPath('action', 'Unsubscribe');

    expect(array_map(static fn ($cookie): string => $cookie->getName(), $page->headers->getCookies()))->not->toContain('tw_store');
});

it('refuses a missing or bad signature alike, on a store on, off or unknown, and changes nothing', function () {
    $sara = Wx::agreeing('sara@example.test', 'ae');
    $signed = URL::signedRoute('storefront.ops.unsubscribe', ['store' => 'ae', 'locale' => 'en', 'customer' => $sara], null, false);
    stopEmailStoreOff();

    foreach (["/ae/en/unsubscribe/{$sara}", "/sa/en/unsubscribe/{$sara}", "/zz/en/unsubscribe/{$sara}", $signed.'0'] as $address) {
        getJson($address)->assertForbidden();
        postJson($address)->assertForbidden();
    }

    // Another store's address under the same signature is not what was signed.
    postJson(str_replace('/ae/', '/sa/', $signed))->assertForbidden();

    expect(stopEmailConsent($sara))->toBeTrue();
});

it('leaves everything else of a switched-off store a 404', function () {
    stopEmailStoreOff();

    get('/ae/en')->assertNotFound();
    get('/ae/en/account/notifications')->assertNotFound();
    postJson('/ae/en/newsletter', ['email' => 'guest@example.test'])->assertNotFound();
    get('/ae/en/newsletter/confirm/abc')->assertNotFound();

    expect(DB::table('ops.subscribers')->count())->toBe(0);
});

it('withdraws the consent within the request, with the queue not running', function () {
    $sara = Wx::agreeing('sara@example.test');
    event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
    $signed = URL::signedRoute('storefront.ops.unsubscribe', ['store' => 'sa', 'locale' => 'en', 'customer' => $sara], null, false);
    // Whatever is queued now only waits in the `jobs` table.
    config(['queue.default' => 'database']);

    postJson($signed)->assertOk();

    expect(stopEmailConsent($sara))->toBeFalse()
        ->and(DB::table('access.customers')->where('id', $sara)->value('marketing_consent_source'))->toBe('UNSUBSCRIBE_LINK')
        // The list follows from the queue; the job that withdrew it does not wait there.
        ->and(DB::table('jobs')->where('payload', 'like', '%WithdrawMarketingConsentJob%')->count())->toBe(0);
});

it('leaves the webhook\'s withdrawal on the queue: the provider is no person waiting', function () {
    $sara = Wx::agreeing('sara@example.test');
    event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
    config(['queue.default' => 'database', 'services.brevo.webhook_secret' => 'secret']);

    call('POST', '/newsletter/brevo', [], [], [], ['HTTP_X_TOUCHWOOD_SECRET' => 'secret', 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['event' => 'unsubscribe', 'email' => 'sara@example.test']))->assertNoContent();

    expect(stopEmailConsent($sara))->toBeTrue()
        ->and(DB::table('jobs')->where('payload', 'like', '%WithdrawMarketingConsentJob%')->count())->toBe(1);
});
