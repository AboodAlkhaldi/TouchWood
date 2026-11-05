<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\CapturedNewsletterList;

use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

/*
| Ops' shop screens in a real browser (ops.md §4.15 O11, O12, O13): the newsletter box in the footer for a guest, its
| answers in words; the confirmation page its email links to, and the leave and unsubscribe pages; the offers switch on
| the account's Profile tab and the box on registration. Both languages, and a phone's width after the page has settled.
|
| Deliberately no RefreshDatabase, as the other browser tests: every address is new each run. The cache is emptied first,
| which also clears the box's limits (20 sign-ups an hour from one address: every run here comes from 127.0.0.1).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    Cache::flush();
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CapturedEmails::install();
    CapturedNewsletterList::install();
});

const OPS_SHOP_SCREENS_WIDE = 'document.documentElement.scrollWidth <= window.innerWidth';

/**
 * An address nobody has used before, because this suite keeps what it writes: the ULID's random end. Kept short: the
 * Profile tab shows the address whole, and one of 47 characters is wider than a phone (seen 2026-11-06).
 */
function opsShopScreensAddress(string $what): string
{
    return $what.substr(strtolower((string) Str::ulid()), -16).'@example.test';
}

/**
 * A stand-in for the server's answers to the page's next JSON posts to an address ending in `$ending` (the box's, or an
 * email link's): each answer `{status, body}` once, in order, or 'none' for no answer at all; after them, the real server.
 * Wrapped as a statement, since the plugin calls a script whose value is a function.
 *
 * @param  list<array{status: int, body: array<string, mixed>}|'none'>  $answers
 */
function opsShopScreensAnswers(mixed $page, string $ending, array $answers): void
{
    $json = json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $page->script(<<<JS
        (() => {
            const answers = {$json};
            const real = window.__opsRealFetch ?? window.fetch;
            window.__opsRealFetch = real;
            window.__opsPosts = 0;
            window.fetch = (url, init) => {
                if (init?.method === 'POST' && String(url).split('?')[0].endsWith('{$ending}')) {
                    window.__opsPosts += 1;
                    const answer = answers.shift();
                    if (answer === 'none') {
                        return Promise.reject(new TypeError('Failed to fetch'));
                    }
                    if (answer !== undefined) {
                        return Promise.resolve(new Response(JSON.stringify(answer.body), { status: answer.status, headers: { 'Content-Type': 'application/json' } }));
                    }
                }
                return real(url, init);
            };
        })()
        JS);
}

/** The text of the box's line under the button, or of its box's message. */
function opsShopScreensBoxSays(mixed $page, string $text): bool
{
    $quoted = json_encode($text, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    return browserSoon($page, "document.querySelector('[data-test=\"newsletter-box\"]').textContent.replace(/[\\u2068\\u2069]/g, '').includes({$quoted})");
}

it('says each of the box\'s answers in words, keeping what was typed, in English', function () {
    $page = visit('/sa/en');
    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-email\"]') !== null"))->toBeTrue();
    $email = opsShopScreensAddress('answers');
    $page->type('[data-test="newsletter-email"]', $email);
    opsShopScreensAnswers($page, '/newsletter', [
        ['status' => 422, 'body' => ['type' => 'ops.invalid_ops_input', 'detail' => 'email']],
        ['status' => 429, 'body' => ['retryAfter' => 61]],
        ['status' => 429, 'body' => ['retryAfter' => 30]],
        ['status' => 503, 'body' => ['type' => 'ops.newsletter_unavailable', 'detail' => 'We couldn\'t send the message just now. Try again in a few minutes.']],
        'none',
        ['status' => 500, 'body' => []],
    ]);

    $press = fn () => $page->click('[data-test="newsletter-subscribe"]');
    $press();
    expect(opsShopScreensBoxSays($page, 'Email address takes an address like name@example.com.'))->toBeTrue('422');
    $press();
    expect(opsShopScreensBoxSays($page, 'Too many tries. Try again in 2 minutes.'))->toBeTrue('429, 61 seconds');
    $press();
    expect(opsShopScreensBoxSays($page, 'Too many tries. Try again in 1 minute.'))->toBeTrue('429, 30 seconds');
    $press();
    expect(opsShopScreensBoxSays($page, 'We couldn\'t send the message just now.'))->toBeTrue('503');
    $press();
    expect(opsShopScreensBoxSays($page, 'Couldn\'t subscribe just now. Try again.'))->toBeTrue('no answer');
    $press();
    expect(opsShopScreensBoxSays($page, 'Couldn\'t subscribe just now. Try again.'))->toBeTrue('500')
        ->and((int) $page->script('window.__opsPosts'))->toBe(6)
        ->and((string) $page->script("document.querySelector('[data-test=\"newsletter-email\"]').value"))->toBe($email)
        ->and(DB::table('ops.subscribers')->where('email', $email)->exists())->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('says the wait in Arabic in each plural form, with Latin digits', function () {
    $page = visit('/sa/ar');
    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-email\"]') !== null"))->toBeTrue();
    $page->type('[data-test="newsletter-email"]', opsShopScreensAddress('wait'));
    opsShopScreensAnswers($page, '/newsletter', [
        ['status' => 429, 'body' => ['retryAfter' => 60]],
        ['status' => 429, 'body' => ['retryAfter' => 120]],
        ['status' => 429, 'body' => ['retryAfter' => 179]],
        ['status' => 429, 'body' => ['retryAfter' => 660]],
        ['status' => 429, 'body' => ['retryAfter' => 6000]],
    ]);

    foreach (['بعد دقيقة واحدة.', 'بعد دقيقتين.', 'بعد 3 دقائق.', 'بعد 11 دقيقة.', 'بعد 100 دقيقة.'] as $said) {
        $page->click('[data-test="newsletter-subscribe"]');
        expect(opsShopScreensBoxSays($page, 'محاولات كثيرة. حاول مرة أخرى '.$said))->toBeTrue($said);
    }
});

it('sends the box again with a fresh token when the session behind the page has expired', function () {
    $email = opsShopScreensAddress('stale');
    $page = visit('/sa/en');
    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-email\"]') !== null"))->toBeTrue();
    // The tests' server checks no token (Laravel skips it under unit tests), so the expired session's 419 is a stand-in;
    // what follows it - the page read again, the second send - is the real server.
    opsShopScreensAnswers($page, '/newsletter', [['status' => 419, 'body' => ['message' => 'CSRF token mismatch.']]]);

    $page->type('[data-test="newsletter-email"]', $email)->click('[data-test="newsletter-subscribe"]');
    // The server refused the stale token (419); the page read itself again and the second send went through.
    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-sent\"]') !== null"))->toBeTrue()
        ->and((int) $page->script('window.__opsPosts'))->toBe(2)
        ->and(DB::table('ops.subscribers')->where('email', $email)->exists())->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('keeps the box drawn from one page to the next', function () {
    $page = visit('/sa/en');
    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-box\"]') !== null"))->toBeTrue();
    // Marked, then watched across an Inertia visit: the box never leaves the page.
    $page->script("(() => { window.__opsBoxGone = false; new MutationObserver(() => { if (document.querySelector('[data-test=\"newsletter-box\"]') === null) { window.__opsBoxGone = true; } }).observe(document.body, { childList: true, subtree: true }); })()");
    $page->click('[data-test="language-ar"]');
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"newsletter-subscribe\"]')?.textContent === 'اشترك'"))->toBeTrue()
        ->and($page->script('window.__opsBoxGone'))->toBeFalse();
});

it('subscribes a guest from the footer, then confirms from the emailed link, on a phone too', function () {
    $email = opsShopScreensAddress('box');
    $page = visit('/sa/en');

    // The box comes once the page has drawn.
    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-box\"]') !== null"))->toBeTrue()
        ->and(browserUntil($page, "document.querySelector('#newsletter-title').textContent === 'Our Newsletter'"))->toBeTrue();

    // Checked as typed: not an address yet.
    $page->type('[data-test="newsletter-email"]', 'not an address')->click('[data-test="newsletter-subscribe"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-box\"]').textContent.includes('Email address takes an address like name@example.com.')"))->toBeTrue()
        ->and(DB::table('ops.subscribers')->where('email', 'not an address')->exists())->toBeFalse();

    $page->type('[data-test="newsletter-email"]', $email)->click('[data-test="newsletter-subscribe"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-sent\"]')?.textContent === 'Check your email: open the link we sent to finish subscribing.'"))->toBeTrue()
        ->and(DB::table('ops.subscribers')->where('email', $email)->value('confirmed_at'))->toBeNull();
    $page->assertNoJavaScriptErrors();

    // The emailed link opens the page; it confirms nothing until the button.
    preg_match('#(/sa/en/newsletter/confirm/[A-Za-z0-9]+)#', CapturedEmails::installed()->last()->text, $match);
    $link = $match[1] ?? throw new LogicException('No confirmation link.');
    $page->navigate($link, BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"email-link-action\"]')?.textContent === 'Confirm'"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('meta[name=\"robots\"]')?.content ?? ''"))->toBe('noindex')
        ->and((string) $page->script('document.documentElement.dir'))->toBe('ltr')
        ->and(DB::table('ops.subscribers')->where('email', $email)->value('confirmed_at'))->toBeNull();

    $page->resize(375, 812);
    expect(browserSoon($page, OPS_SHOP_SCREENS_WIDE))->toBeTrue('the confirmation page scrolls sideways on a phone');

    $page->click('[data-test="email-link-action"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"email-link-done\"]')?.textContent === 'You are subscribed. Every newsletter has a link to unsubscribe.'"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"email-link-shop\"]').getAttribute('href')"))->toBe('/sa/en')
        ->and(DB::table('ops.subscribers')->where('email', $email)->value('confirmed_at'))->not->toBeNull();

    // The same link a second time: its words in the button's place.
    $page->navigate($link, BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"email-link-action\"]') !== null"))->toBeTrue();
    $page->click('[data-test="email-link-action"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"email-link-refused\"]')?.textContent.includes('isn\\'t valid any more') === true && document.querySelector('[data-test=\"email-link-action\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('tells a guest to use the account an email belongs to, with the way in, and fits a phone', function () {
    $owner = opsShopScreensAddress('owner');
    Fx::customer($owner);
    $page = visit('/sa/en');
    $page->resize(375, 812);

    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-email\"]') !== null"))->toBeTrue();
    // Enter in the box sends it too: the button is no submit button, so a page's own form keeps the only one.
    expect((int) $page->script('document.querySelectorAll(\'[data-test="newsletter-box"] button[type="submit"]\').length'))->toBe(0);
    $page->type('[data-test="newsletter-email"]', $owner)->keys('[data-test="newsletter-email"]', 'Enter');
    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-refusal\"]')?.textContent.includes('This email belongs to an account.') === true"))->toBeTrue()
        ->and((string) $page->script("new URL(document.querySelector('[data-test=\"newsletter-sign-in\"]').href).pathname"))->toBe('/sa/en/sign-in')
        // What was typed stays in the box.
        ->and((string) $page->script("document.querySelector('[data-test=\"newsletter-email\"]').value"))->toBe($owner)
        ->and(browserSoon($page, OPS_SHOP_SCREENS_WIDE))->toBeTrue('the footer box scrolls sideways on a phone');
    $page->assertNoJavaScriptErrors();
});

it('draws the box in Arabic, right to left, and the leave page in Arabic', function () {
    $page = visit('/sa/ar');
    expect(browserSoon($page, "document.querySelector('[data-test=\"newsletter-subscribe\"]')?.textContent === 'اشترك'"))->toBeTrue()
        ->and((string) $page->script('document.documentElement.dir'))->toBe('rtl');

    $email = opsShopScreensAddress('leaver');
    $guest = strtolower((string) Str::ulid());
    DB::table('ops.subscribers')->insert([
        'id' => $guest, 'store_id' => Fx::storeId('sa'), 'email' => $email, 'locale' => 'ar', 'kind' => 'GUEST',
        'customer_id' => null, 'token_hash' => null, 'created_at' => now(), 'confirmed_at' => now(),
    ]);
    $signed = URL::signedRoute('storefront.ops.newsletter.leave', ['store' => 'sa', 'locale' => 'ar', 'subscriber' => $guest], null, false);

    $page->navigate($signed, BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('h1')?.textContent === 'مغادرة النشرة'"))->toBeTrue()
        ->and((string) $page->script('document.documentElement.dir'))->toBe('rtl');
    $page->resize(375, 812);
    expect(browserSoon($page, OPS_SHOP_SCREENS_WIDE))->toBeTrue('the leave page scrolls sideways on a phone');

    $page->click('[data-test="email-link-action"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"email-link-done\"]')?.textContent === 'تم إلغاء اشتراكك. يمكنك الاشتراك مجددًا في أي وقت.'"))->toBeTrue()
        ->and(browserUntil($page, "document.querySelector('[data-test=\"email-link-shop\"]') === null"))->toBeTrue()
        ->and(DB::table('ops.subscribers')->where('id', $guest)->exists())->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('ticks offers on registration, then flips the switch on the Profile tab, with no box for the customer', function () {
    $email = opsShopScreensAddress('consent');
    $page = visit('/sa/en/register');
    $page->click('[data-test="account-type-individual"]')
        ->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', $email)
        ->type('#password', 'a long enough password')
        ->click('[data-test="terms"]')
        ->click('[data-test="marketing-consent"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en');

    expect((bool) DB::table('access.customers')->where('email', $email)->value('marketing_consent'))->toBeTrue()
        // A signed-in customer gets no box: their switch is in the account.
        ->and(browserUntil($page, "document.querySelector('[data-test=\"newsletter-box\"]') !== null"))->toBeFalse();

    $page->navigate('/sa/en/account?tab=profile', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"offers-card\"] [data-test=\"marketing-consent\"]')?.getAttribute('data-state') === 'checked'"))->toBeTrue()
        // Not confirmed yet: it says so.
        ->and(browserUntil($page, "document.querySelector('[data-test=\"offers-unconfirmed\"]')?.textContent === 'These can only reach you once your email is confirmed.'"))->toBeTrue();

    $page->click('[data-test="offers-card"] [data-test="marketing-consent"]');
    // The toast comes with the server's answer, so the row is saved by then.
    expect(browserSoon($page, "document.querySelector('[data-test=\"offers-card\"] [data-test=\"marketing-consent\"]').getAttribute('data-state') === 'unchecked' && document.body.textContent.includes('Changes saved')"))->toBeTrue()
        ->and((bool) DB::table('access.customers')->where('email', $email)->value('marketing_consent'))->toBeFalse();

    // After a reload it stays as saved.
    $page->navigate('/sa/en/account?tab=profile', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"offers-card\"] [data-test=\"marketing-consent\"]')?.getAttribute('data-state') === 'unchecked'"))->toBeTrue();
    $page->resize(375, 812);
    expect(browserSoon($page, OPS_SHOP_SCREENS_WIDE))->toBeTrue('the Profile tab scrolls sideways on a phone');
    $page->assertNoJavaScriptErrors();
});

it('stops offers and news from a customer\'s unsubscribe link', function () {
    $email = opsShopScreensAddress('unsub');
    $customer = Fx::customer($email);
    DB::table('access.customers')->where('id', $customer)->update(['marketing_consent' => true]);
    $signed = URL::signedRoute('storefront.ops.unsubscribe', ['store' => 'sa', 'locale' => 'en', 'customer' => $customer], null, false);

    $page = visit($signed);
    expect(browserSoon($page, "document.querySelector('h1')?.textContent === 'Unsubscribe from Offers and News'"))->toBeTrue()
        ->and((bool) DB::table('access.customers')->where('id', $customer)->value('marketing_consent'))->toBeTrue();
    $page->resize(375, 812);
    expect(browserSoon($page, OPS_SHOP_SCREENS_WIDE))->toBeTrue('the unsubscribe page scrolls sideways on a phone');

    // Anything but the link's own refusal - a server error, a 404 of another kind, no answer - says "try again" and
    // keeps the button.
    opsShopScreensAnswers($page, '/unsubscribe/'.$customer, [
        ['status' => 500, 'body' => []],
        ['status' => 404, 'body' => ['type' => 'http.404', 'detail' => 'Not Found']],
        'none',
    ]);
    foreach (['500', 'another 404', 'no answer'] as $n => $case) {
        $posts = $n + 1;
        $page->click('[data-test="email-link-action"]');
        expect(browserSoon($page, "window.__opsPosts === {$posts} && document.querySelector('[data-test=\"email-link-failed\"]')?.textContent === 'That didn\\'t go through. Try again.' && document.querySelector('[data-test=\"email-link-action\"]') !== null && document.querySelector('[data-test=\"email-link-refused\"]') === null"))->toBeTrue($case);
    }
    expect((bool) DB::table('access.customers')->where('id', $customer)->value('marketing_consent'))->toBeTrue();

    $page->click('[data-test="email-link-action"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"email-link-done\"]') !== null"))->toBeTrue()
        ->and((bool) DB::table('access.customers')->where('id', $customer)->value('marketing_consent'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('confirms with a fresh token when the session behind the page has expired', function () {
    $email = opsShopScreensAddress('fresh');
    postJson('/sa/en/newsletter', ['email' => $email])->assertStatus(202);
    preg_match('#(/sa/en/newsletter/confirm/[A-Za-z0-9]+)#', CapturedEmails::installed()->last()->text, $match);
    $link = $match[1] ?? throw new LogicException('No confirmation link.');

    $page = visit($link);
    expect(browserSoon($page, "document.querySelector('[data-test=\"email-link-action\"]') !== null"))->toBeTrue();
    opsShopScreensAnswers($page, $link, [['status' => 419, 'body' => ['message' => 'CSRF token mismatch.']]]);
    $page->click('[data-test="email-link-action"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"email-link-done\"]') !== null"))->toBeTrue()
        ->and((int) $page->script('window.__opsPosts'))->toBe(2)
        ->and(DB::table('ops.subscribers')->where('email', $email)->value('confirmed_at'))->not->toBeNull();
    $page->assertNoJavaScriptErrors();
});

/**
 * A stand-in on the Profile tab's switch post (Inertia's own request): 'refuse' sends a value the server refuses, 'drop'
 * sends it nowhere (no answer at all), 'slow' holds it a second and a half first.
 */
function opsShopScreensSwitchPost(mixed $page, string $mode): void
{
    $page->script(<<<JS
        (() => {
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            window.__opsSwitchPosts = 0;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__opsSwitch = method.toUpperCase() === 'POST' && String(url).includes('/account/marketing');
                if (this.__opsSwitch && '{$mode}' === 'drop') {
                    return open.call(this, method, 'http://127.0.0.1:9/nowhere', ...rest);
                }
                return open.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (!this.__opsSwitch) {
                    return send.call(this, body);
                }
                window.__opsSwitchPosts += 1;
                if ('{$mode}' === 'refuse') {
                    return send.call(this, JSON.stringify({ marketing_consent: 'maybe' }));
                }
                if ('{$mode}' === 'slow') {
                    setTimeout(() => send.call(this, body), 1500);
                    return;
                }
                return send.call(this, body);
            };
        })()
        JS);
}

it('puts the switch back on a refusal and on no answer, and takes one flip at a time', function () {
    $email = opsShopScreensAddress('flip');
    $page = visit('/sa/en/register');
    $page->click('[data-test="account-type-individual"]')
        ->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', $email)
        ->type('#password', 'a long enough password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en');
    $switch = '[data-test="offers-card"] [data-test="marketing-consent"]';
    $state = "document.querySelector('{$switch}')?.getAttribute('data-state')";
    $consent = fn (): bool => (bool) DB::table('access.customers')->where('email', $email)->value('marketing_consent');

    $page->navigate('/sa/en/account?tab=profile', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "{$state} === 'unchecked'"))->toBeTrue();

    // Refused by the server: back, and why.
    opsShopScreensSwitchPost($page, 'refuse');
    $page->click($switch);
    expect(browserSoon($page, "{$state} === 'unchecked' && (document.querySelector('[data-test=\"offers-refusal\"]')?.textContent ?? '') !== '' && window.__opsSwitchPosts === 1"))->toBeTrue('refused')
        ->and($consent())->toBeFalse();

    // No answer at all: back, and said.
    $page->navigate('/sa/en/account?tab=profile', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "{$state} === 'unchecked'"))->toBeTrue();
    opsShopScreensSwitchPost($page, 'drop');
    $page->click($switch);
    expect(browserSoon($page, "{$state} === 'unchecked' && document.querySelector('[data-test=\"offers-refusal\"]')?.textContent === 'Couldn\\'t save just now. Try again.'"))->toBeTrue('no answer')
        ->and($consent())->toBeFalse();

    // Two quick flips: the second waits on the first, so one post goes and the switch ends on.
    $page->navigate('/sa/en/account?tab=profile', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "{$state} === 'unchecked'"))->toBeTrue();
    opsShopScreensSwitchPost($page, 'slow');
    $page->click($switch);
    expect(browserSoon($page, "document.querySelector('{$switch}').getAttribute('aria-disabled') === 'true'"))->toBeTrue('not held while saving');
    $page->script("(() => { document.querySelector('{$switch}').click(); })()");
    expect(browserSoon($page, "{$state} === 'checked' && document.querySelector('{$switch}').getAttribute('aria-disabled') === null && document.body.textContent.includes('Changes saved')"))->toBeTrue('one flip')
        ->and((int) $page->script('window.__opsSwitchPosts'))->toBe(1)
        ->and($consent())->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
