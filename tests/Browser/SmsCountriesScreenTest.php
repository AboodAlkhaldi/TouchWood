<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| O10, SMS Countries in a real browser (ops.md §4.15): a Super Admin adds a country through the dialog, changes its
| calling code, removes it after reading who it affects, and the page fits a phone. The suite's database is not
| reset between runs: the country is gone at the end.
*/

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so the two would deadlock.

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('adds a country, changes its calling code, removes it, and fits a phone', function () {
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');
    DB::table('ops.sms_countries')->where('country_code', 'AE')->delete();

    try {
        $page = visit('/admin/sign-in')
            ->type('#email', $email)
            ->type('#password', Fx::STAFF_PASSWORD)
            ->click('button[type="submit"]')
            ->assertPathIs('/admin/sign-in/code')
            ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
            ->click('button[type="submit"]');

        expect(signedInToPanel($page))->toBeTrue();
        $page->navigate('/admin/sms-countries');
        $page->assertVisible('[data-test="log-note"]')->assertVisible('[data-test="country-SA"]')->assertSee('SMS Countries')->assertNoJavaScriptErrors();

        // Add Country: a calling code that isn't one is said as it is typed.
        $page->click('[data-test="add-country"]');
        expect(browserSoon($page, "document.querySelector('#calling-code') !== null"))->toBeTrue();
        $page->click('#country');
        expect(browserSoon($page, "document.querySelector('[cmdk-input]') !== null"))->toBeTrue();
        $page->type('[cmdk-input]', 'United Arab');
        expect(browserSoon($page, "document.querySelector('[cmdk-item]') !== null"))->toBeTrue();
        $page->click('[cmdk-item]');
        $page->type('#calling-code', '971');
        $page->click('[data-test="confirm-add-country"]');

        expect(browserSoon($page, "document.querySelector('[data-test=\"country-AE\"]') !== null"))->toBeTrue()
            ->and(DB::table('ops.sms_countries')->where('country_code', 'AE')->value('calling_code'))->toBe('971');
        $page->assertSeeIn('[data-test="country-AE"] [data-test="calling-code"]', '+971');

        // A code another country has: refused on its box, the dialog staying open.
        $page->click('[data-test="more-AE"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"change-calling-code\"]') !== null"))->toBeTrue();
        $page->click('[data-test="change-calling-code"]');
        expect(browserSoon($page, "document.querySelector('#calling-code') !== null"))->toBeTrue();
        $page->clear('#calling-code')->type('#calling-code', '966');
        $page->click('[data-test="confirm-calling-code"]');
        expect(browserSoon($page, "document.querySelector('#calling-code-error, #calling-code-message') !== null || document.querySelector('[data-slot=\"field-error\"]') !== null"))->toBeTrue()
            ->and(DB::table('ops.sms_countries')->where('country_code', 'AE')->value('calling_code'))->toBe('971');

        $page->clear('#calling-code')->type('#calling-code', '972');
        $page->click('[data-test="confirm-calling-code"]');
        expect(browserSoon($page, "document.querySelector('#calling-code') === null"))->toBeTrue();
        expect(browserSoon($page, "document.querySelector('[data-test=\"country-AE\"] [data-test=\"calling-code\"]')?.textContent === '+972'"))->toBeTrue();

        // A phone: nothing scrolls sideways.
        $page->resize(375, 812);
        expect(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
        $page->resize(1280, 800);

        // Remove says who it affects, then removes.
        $page->click('[data-test="more-AE"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"remove-country\"]') !== null"))->toBeTrue();
        $page->click('[data-test="remove-country"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-remove-country\"]') !== null"))->toBeTrue();
        $page->click('[data-test="confirm-remove-country"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"country-AE\"]') === null"))->toBeTrue()
            ->and(DB::table('ops.sms_countries')->where('country_code', 'AE')->exists())->toBeFalse();

        // And in Arabic: right to left, its calling codes still left to right in Latin digits, and it fits a phone.
        $page->click('[data-test="person-menu"]')->click('[data-test="language"]')->assertSee('دول الرسائل النصية');
        expect($page->script('document.documentElement.dir'))->toBe('rtl')
            ->and($page->script("document.querySelector('[data-test=\"country-SA\"] [data-test=\"calling-code\"]').textContent"))->toBe('+966');
        $page->resize(375, 812);
        expect(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
        $page->assertNoJavaScriptErrors();
    } finally {
        DB::table('ops.sms_countries')->where('country_code', 'AE')->delete();
    }
});
