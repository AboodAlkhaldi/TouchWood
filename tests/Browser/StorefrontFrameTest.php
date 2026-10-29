<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\seed;

/*
| The shop's frame in a real browser (stage 2b, step 4, frontend.md §2.3, §3.6).
|
| The feature tests beside this one know the server sends the right props. They cannot know whether
| the page comes to life, or whether the two switches in the header actually take somebody
| anywhere - and the country and the language are the whole of what this stage's shop does.
|
| No RefreshDatabase, and the cache is emptied first, for the reasons written out at the top of
| AccountScreenTest: this suite runs against whatever `composer check` migrated, and a store
| directory left warm by the last run outlives the stores it names.
*/

beforeEach(function () {
    // Served inside this very process, so it inherits phpunit.xml's "array" driver, which keeps
    // nothing between requests.
    config(['session.driver' => 'database']);
    Cache::flush();
    seed(PlatformSeeder::class);
});

it('draws the country page and goes into the store somebody picks', function () {
    // A visitor with no cookie has chosen nothing, which is the only reason this page exists.
    visit('/')
        ->assertSee('اختر دولتك')
        ->click('السعودية')
        ->assertPathIs('/sa/ar')
        ->assertSee('السعودية')
        ->assertNoJavaScriptErrors();
});

it('reads the language from the address, not from the last one this browser used', function () {
    // The header shares a language before any route middleware has run, and it prefers the
    // remembered one - so /sa/en came back in English inside an Arabic, right-to-left page
    // (found by running it, 2026-09-24). Visiting Arabic first is what leaves the cookie behind.
    $page = visit('/sa/ar');

    expect($page->script('document.documentElement.lang'))->toBe('ar')
        ->and($page->script('document.documentElement.dir'))->toBe('rtl');

    $page->click('[data-test="language-en"]')->assertPathIs('/sa/en');

    expect($page->script('document.documentElement.lang'))->toBe('en')
        ->and($page->script('document.documentElement.dir'))->toBe('ltr');

    $page->assertSee('Saudi Arabia')->assertNoJavaScriptErrors();
});

it('moves to another country and keeps the language it was being read in', function () {
    // Another country is another shop - other prices, stock and delivery - so it lands on that
    // store's home rather than on the same page under a different code.
    visit('/sa/en')
        ->select('[data-test="country-switch"]', 'eg')
        ->assertPathIs('/eg/en')
        ->assertSee('Egypt')
        ->assertNoJavaScriptErrors();
});

it('fits the country page and the shop\'s header on a phone, both ways round', function () {
    // Phones are supported (frontend.md §2.1): the header's switches, the logo and the menus fit the
    // screen, in English and in Arabic, with nothing scrolling sideways.
    $page = visit('/');
    $page->resize(375, 812);
    $page->assertSee('اختر دولتك');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue('the country page scrolls sideways');

    foreach (['/sa/en', '/sa/ar', '/eg/en'] as $address) {
        $page->navigate($address, BROWSER_PAGE_LOAD)->assertPresent('[data-test="country-switch"]');
        expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue("{$address} scrolls sideways")
            ->and($page->script("document.querySelector('header').getBoundingClientRect().right <= window.innerWidth"))->toBeTrue("{$address}'s header is wider than the screen");
    }

    $page->assertNoJavaScriptErrors();
});

it('turns the shop dark when the button is pressed', function () {
    // The button posted to the panel's endpoint, behind the panel's session, so the shop's token
    // was never the right one: every press was refused, and a refused press leaves the page
    // exactly as it was - which is what a broken button looks like (owner, 2026-09-25).
    $page = visit('/sa/ar');

    expect($page->script('document.documentElement.dataset.mode'))->toBe('light');

    // The theme switch is Geist's now: Light and Dark side by side (frontend.md 1.10). Pressing only
    // dispatches the post, and reading the page in the same breath raced and lost, so the page is
    // read until the server's answer has arrived (lesson 121: a promise that answers within 4 s).
    $page->click('[data-test="theme-dark"]');

    // Asked for again and answered dark by the server, which is why nothing flashes.
    expect(browserUntil($page, "document.documentElement.dataset.mode === 'dark'"))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('draws the logo on a cream tile on a light page and a navy tile on a dark one', function () {
    // The owner's logo answers of 2026-10-04 (frontend.md §1.11): the tile follows the page, by the
    // stylesheet, so the first paint is already right.
    $light = visit('/sa/en');
    expect(browserUntil($light, "getComputedStyle(document.querySelector('header [data-logo=\"auto\"] rect')).fill === 'rgb(246, 239, 232)'"))->toBeTrue()
        // The frame in the deep copper on cream, as the owner's pictures.
        ->and($light->script("getComputedStyle(document.querySelector('header [data-logo=\"auto\"] [data-logo-part=\"frame\"]')).fill"))->toBe('rgb(134, 71, 32)');

    $dark = visit('/sa/en')->inDarkMode();
    expect(browserUntil($dark, "getComputedStyle(document.querySelector('header [data-logo=\"auto\"] rect')).fill === 'rgb(2, 54, 94)'"))->toBeTrue();
});

it('answers a mistyped shop address with the shop\'s own not-found page, in the address\'s language, and goes back to the store', function () {
    // The shop's error page (frontend.md §2.6; owner, 2026-10-20): the shop's frame, the shopper's sentence,
    // Back to the Store - never Laravel's bare page.
    $page = visit('/sa/en/no-such-page');

    expect(browserSoon($page, "document.querySelector('[data-test=\"error-sentence\"]')?.textContent === \"This page isn't available. Check the address, or go back to the store.\" && document.querySelector('h1')?.textContent === 'Page Not Found'"))->toBeTrue()
        // In the shop's frame: its country switch is there.
        ->and(browserSoon($page, "document.querySelector('[data-test=\"country-switch\"]') !== null && document.documentElement.dir === 'ltr'"))->toBeTrue();

    $page->click('[data-test="error-back"]');

    expect(browserSoon($page, "window.location.pathname === '/sa/en'"))->toBeTrue();
    $page->assertNoJavaScriptErrors();

    $arabic = visit('/sa/ar/no/such/page');

    expect(browserSoon($arabic, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"error-sentence\"]')?.textContent === 'هذه الصفحة غير متاحة. تحقّق من العنوان، أو عُد إلى المتجر.'"))->toBeTrue()
        ->and(browserSoon($arabic, "document.querySelector('[data-test=\"error-back\"]')?.getAttribute('href') === '/sa/ar' && document.querySelector('[data-test=\"error-back\"]')?.textContent === 'العودة إلى المتجر'"))->toBeTrue();
});
