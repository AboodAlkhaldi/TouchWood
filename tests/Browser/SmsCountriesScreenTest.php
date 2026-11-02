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
        $page->assertSeeIn('[data-test="log-note"]', 'Log writes codes to the server');

        // Add Country: a listed country is out of reach; a calling code that isn't one holds the button back, and
        // Arabic digits become Latin as they are typed.
        $page->click('[data-test="add-country"]');
        expect(browserSoon($page, "document.querySelector('#calling-code') !== null"))->toBeTrue();
        $page->assertSeeIn('[data-test="calling-code-plus"]', '+');
        $page->click('#country');
        expect(browserSoon($page, "document.querySelector('[cmdk-input]') !== null"))->toBeTrue();
        $page->type('[cmdk-input]', 'Saudi');
        expect(browserSoon($page, "document.querySelector('[cmdk-item]')?.getAttribute('aria-disabled') === 'true'"))->toBeTrue();
        $page->clear('[cmdk-input]')->type('[cmdk-input]', 'United Arab');
        expect(browserSoon($page, "document.querySelector('[cmdk-item]') !== null && document.querySelector('[cmdk-item]').getAttribute('aria-disabled') !== 'true'"))->toBeTrue();
        $page->click('[cmdk-item]');
        $page->type('#calling-code', '0');
        expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-add-country\"]').getAttribute('aria-disabled') === 'true'"))->toBeTrue();
        $page->clear('#calling-code')->type('#calling-code', '٩٧١');
        expect(browserSoon($page, "document.querySelector('#calling-code').value === '971'"))->toBeTrue();
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

        // Change Provider saves through its dialog.
        $page->click('[data-test="more-AE"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"change-provider\"]') !== null"))->toBeTrue();
        $page->click('[data-test="change-provider"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-provider\"]') !== null"))->toBeTrue();
        $page->click('[data-test="confirm-provider"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-provider\"]') === null"))->toBeTrue();

        // Remove says who it affects, and Keep keeps it.
        $page->click('[data-test="more-AE"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"remove-country\"]') !== null"))->toBeTrue();
        $page->click('[data-test="remove-country"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-remove-country\"]') !== null"))->toBeTrue();
        $page->assertSee('Customers with one can\'t order until they change it.');
        $page->click('Keep');
        expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-remove-country\"]') === null"))->toBeTrue()
            ->and(DB::table('ops.sms_countries')->where('country_code', 'AE')->exists())->toBeTrue();

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
