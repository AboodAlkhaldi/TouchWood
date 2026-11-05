<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Platform\Public\PlatformPermissions as Platform;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\CapturedNewsletterList;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

/*
| The shop's screens of Ops (ops.md §4.15 O11, O13): the newsletter box's words in the frame, for a guest alone, and
| the page each emailed link opens - in the address's language whatever the browser last chose, the store's frame
| never read, and the JSON still answering a screen that asks for it. Opening a page changes nothing.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CapturedEmails::install();
    CapturedNewsletterList::install();
    Queue::fake();
});

/** A browser whose last chosen language is the other one than the address's. */
function newsletterScreensBrowser(string $cookieLocale): AdminBrowser
{
    $browser = new AdminBrowser('10.9.14.'.random_int(20, 250));
    $browser->setCookie(HandleInertiaRequests::LOCALE_COOKIE, $cookieLocale);

    return $browser;
}

/** A confirmed guest on the store's list; its id. */
function newsletterScreensGuest(string $code, string $email): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.subscribers')->insert([
        'id' => $id, 'store_id' => Fx::storeId($code), 'email' => $email, 'locale' => 'ar', 'kind' => 'GUEST',
        'customer_id' => null, 'token_hash' => null, 'created_at' => now(), 'confirmed_at' => now(),
        'brevo_email' => $email, 'brevo_locale' => 'ar', 'brevo_synced_at' => now(),
    ]);

    return $id;
}

/** Whether a query names a table of the `ops` schema - `"ops".x` or `ops.x` - and not one ending in "ops", as "shops". */
function newsletterScreensReadsOps(string $sql): int
{
    return preg_match('/(?:^|[^a-z0-9_".])(?:"ops"|ops)\.["a-z_]/i', $sql);
}

it('tells an ops table from one that only ends in "ops"', function () {
    expect(newsletterScreensReadsOps('select * from "ops"."inbox_entries"'))->toBe(1)
        ->and(newsletterScreensReadsOps('select count(*) from ops.inbox_entries'))->toBe(1)
        ->and(newsletterScreensReadsOps('select * from "catalog"."shops" where "shops"."id" = ?'))->toBe(0)
        ->and(newsletterScreensReadsOps('select * from "workshops"."x"'))->toBe(0);
});

function newsletterScreensStoreOff(string $code): void
{
    DB::table('platform.stores')->where('code', $code)->update(['is_active' => false]);
    cache()->flush();
}

it('opens the confirmation page in the address\'s language, offers the shop, and confirms nothing', function () {
    postJson('/sa/en/newsletter', ['email' => 'guest@example.test'])->assertStatus(202);
    preg_match('#(/sa/en/newsletter/confirm/[A-Za-z0-9]+)#', CapturedEmails::installed()->last()->text, $match);
    $path = $match[1] ?? throw new LogicException('No confirmation link.');

    newsletterScreensBrowser('ar')->get($path)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Storefront/EmailLink')
        ->where('locale', 'en')
        ->where('direction', 'ltr')
        ->where('title', 'Confirm Your Subscription')
        ->where('action', 'Confirm')
        ->where('done', 'You are subscribed. Every newsletter has a link to unsubscribe.')
        ->where('failed', 'That didn\'t go through. Try again.')
        ->where('shopAddress', '/sa/en')
        ->where('shopLabel', 'Go to the Shop')
        ->missing('token'));

    expect(DB::table('ops.subscribers')->where('email', 'guest@example.test')->value('confirmed_at'))->toBeNull();

    // A screen asking for JSON still gets §4.10's answer.
    getJson($path)->assertOk()->assertJsonPath('action', 'Confirm')->assertJsonStructure(['token', 'title', 'text', 'action', 'done']);
});

it('opens a guest\'s leave page for a switched-off store, in Arabic, right to left, with no way to the store', function () {
    $guest = newsletterScreensGuest('ae', 'leaver@example.test');
    newsletterScreensStoreOff('ae');
    $signed = URL::signedRoute('storefront.ops.newsletter.leave', ['store' => 'ae', 'locale' => 'ar', 'subscriber' => $guest], null, false);

    newsletterScreensBrowser('en')->get($signed)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Storefront/EmailLink')
        ->where('locale', 'ar')
        ->where('direction', 'rtl')
        ->where('title', 'مغادرة النشرة')
        ->where('failed', 'لم يتم ذلك. حاول مرة أخرى.')
        ->where('shopAddress', null)
        ->where('shopLabel', null)
        ->missing('shop')
        ->missing('frame'));

    expect(DB::table('ops.subscribers')->where('id', $guest)->exists())->toBeTrue();
    getJson($signed)->assertOk()->assertExactJsonStructure(['title', 'text', 'action', 'done']);
});

it('opens a customer\'s unsubscribe page and changes nothing', function () {
    $sara = Fx::customer();
    DB::table('access.customers')->where('id', $sara)->update(['marketing_consent' => true]);
    $signed = URL::signedRoute('storefront.ops.unsubscribe', ['store' => 'sa', 'locale' => 'en', 'customer' => $sara], null, false);

    newsletterScreensBrowser('ar')->get($signed)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Storefront/EmailLink')
        ->where('locale', 'en')
        ->where('title', 'Unsubscribe from Offers and News')
        ->where('action', 'Unsubscribe')
        ->where('shopAddress', null));

    // The button's post is StopEmailPagesTest's and the browser test's; here, the page alone changed nothing.
    expect((bool) DB::table('access.customers')->where('id', $sara)->value('marketing_consent'))->toBeTrue();
});

it('keeps a bad signature the 403 page, for a plain visit too', function () {
    $sara = Fx::customer();
    $signed = URL::signedRoute('storefront.ops.unsubscribe', ['store' => 'sa', 'locale' => 'en', 'customer' => $sara], null, false);

    newsletterScreensBrowser('en')->get($signed.'0')->assertForbidden();
});

it('carries the newsletter box\'s words to a guest, in the page\'s language, and reads nothing for them', function () {
    $guest = newsletterScreensBrowser('en');
    $guest->get('/sa/en')->assertOk();
    $opsReads = 0;
    DB::listen(function (QueryExecuted $query) use (&$opsReads): void {
        $opsReads += newsletterScreensReadsOps($query->sql);
    });

    $guest->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('frame.ops.newsletter.title', 'Our Newsletter')
        ->where('frame.ops.newsletter.action', 'Subscribe')
        ->where('frame.ops.newsletter.sign_in', 'Sign In')
        ->where('frame.ops.newsletter.too_many.other', 'Too many tries. Try again in :count minutes.')
        ->where('frame.ops.unread', null));

    $guest->get('/sa/ar')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('frame.ops.newsletter.action', 'اشترك')
        ->where('frame.ops.newsletter.too_many.two', 'محاولات كثيرة. حاول مرة أخرى بعد دقيقتين.'));

    expect($opsReads)->toBe(0);
});

it('carries no newsletter box to a signed-in customer', function () {
    Fx::customer();
    $browser = new AdminBrowser('10.9.15.'.random_int(20, 250));
    $browser->post('/sa/en/account/sign-in', ['email' => 'sara@example.test', 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    // The guest's test counts no Ops read; the same recorder sees the bell's, so it is no empty count (lesson 109).
    $opsReads = 0;
    DB::listen(function (QueryExecuted $query) use (&$opsReads): void {
        $opsReads += newsletterScreensReadsOps($query->sql);
    });

    $browser->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('frame.ops.newsletter', null)
        ->whereType('frame.ops.unread', 'integer'));

    expect($opsReads)->toBeGreaterThan(0);
});

it('carries no newsletter box during a staff view', function () {
    $staffId = Fx::staffWith([Platform::SETTINGS_VIEW], ['sa']);
    $browser = new AdminBrowser('10.9.16.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');
    $browser->post('/admin/staff-view', ['store' => 'sa'])->assertRedirect('/sa/en');

    $browser->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('frame.ops.newsletter', null)
        ->whereType('staffView.name', 'string'));
});
