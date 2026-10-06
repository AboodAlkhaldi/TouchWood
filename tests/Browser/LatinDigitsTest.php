<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| Latin digits everywhere, in a real browser (the owner, 2026-10-06; frontend.md §1.8): an Arabic page
| draws its counts, sizes, dates and relative times in 0-9 - what the formatters were asked for is
| proved by what the page shows, since the browser's own Intl is what writes them.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory(B2BFixtures::uploads());
});

/** Whether the page's text holds no Arabic-Indic or Extended Arabic-Indic digit, and some Latin one. */
const LATIN_DIGITS_ONLY = '!/[\\u0660-\\u0669\\u06F0-\\u06F9]/.test(document.body.innerText) && /[0-9]/.test(document.body.innerText)';

it('writes an Arabic admin page\'s figures, sizes and times in Latin digits', function () {
    // A waiting company, so the home has a row with a relative time as well as its counts.
    B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
    $staffId = Fx::staff(superAdmin: true);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'ar']);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    // The home: the cards' counts and storage size, and how long the company has waited.
    $page->assertPresent('[data-test="card-platform.system"]')->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.lang'))->toBe('ar')
        ->and(browserUntil($page, LATIN_DIGITS_ONLY))->toBeTrue();

    // The audit log: every entry's date and time.
    $page->navigate('/admin/audit');
    expect(browserUntil($page, "document.querySelectorAll('[data-test^=\"entry-\"]').length > 0"))->toBeTrue()
        ->and(browserUntil($page, LATIN_DIGITS_ONLY))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
