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

it('turns the shop dark when the button is pressed', function () {
    // The button posted to the panel's endpoint, behind the panel's session, so the shop's token
    // was never the right one: every press was refused, and a refused press leaves the page
    // exactly as it was - which is what a broken button looks like (owner, 2026-09-25).
    $page = visit('/sa/ar');

    expect($page->script('document.documentElement.dataset.mode'))->toBe('light');

    // The button now offers the way back, which it can only do once the server has answered:
    // pressing only dispatches the post, and reading the page in the same breath raced and lost.
    $page->click('[data-test="theme"]')->assertSee('فاتح');

    // Asked for again and answered dark by the server, which is why nothing flashes.
    expect($page->script('document.documentElement.dataset.mode'))->toBe('dark');

    $page->assertNoJavaScriptErrors();
});
