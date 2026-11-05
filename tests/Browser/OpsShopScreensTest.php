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
        ->and((string) $page->script("document.querySelector('[data-test=\"newsletter-sign-in\"]').getAttribute('href')"))->toBe('/sa/en/sign-in')
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

    $page->click('[data-test="email-link-action"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"email-link-done\"]') !== null"))->toBeTrue()
        ->and((bool) DB::table('access.customers')->where('id', $customer)->value('marketing_consent'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});
