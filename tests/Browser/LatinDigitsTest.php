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

/**
 * What the formatters wrote into the elements `$selector` finds: one text per element. Read once the
 * elements are there - a check that waited for "no Arabic digit" would pass on a page caught early.
 *
 * @return list<string>
 */
function latinDigitsTexts(mixed $page, string $selector): array
{
    expect(browserUntil($page, 'document.querySelectorAll('.json_encode($selector).').length > 0'))->toBeTrue();

    /** @var list<string> */
    return $page->script('() => [...document.querySelectorAll('.json_encode($selector).')].map((element) => element.textContent ?? "")');
}

/**
 * Every digit is 0-9; and, unless a text may be words alone ("now"), every text has one.
 *
 * @param  list<string>  $texts
 */
function expectLatinDigits(array $texts, bool $eachHasDigit = true): void
{
    expect($texts)->not->toBeEmpty();

    foreach ($texts as $text) {
        expect($text)->not->toMatch('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u');

        if ($eachHasDigit) {
            expect($text)->toMatch('/[0-9]/');
        }
    }
}

it('writes an Arabic admin page\'s figures, sizes and times in Latin digits', function () {
    // A company waiting three days, so the home has a row whose time has a number in it ("3 days
    // ago"), as well as its counts.
    [, $application] = B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
    DB::table('b2b.applications')->where('id', $application->id())->update(['submitted_at' => now()->subDays(3)]);
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

    // The home: each card's figures - counts, the storage size - and how long the company has waited.
    $page->assertPresent('[data-test="card-platform.system"]')->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.lang'))->toBe('ar');
    expectLatinDigits(latinDigitsTexts($page, '[data-test^="card-"] dd'));
    // A moment may be words alone ("now"); the three days' wait has its number.
    $times = latinDigitsTexts($page, 'time');
    expectLatinDigits($times, eachHasDigit: false);
    expect(implode(' ', $times))->toMatch('/[0-9]/');

    // The audit log: every entry's date and time.
    $page->navigate('/admin/audit');
    expectLatinDigits(latinDigitsTexts($page, '[data-test^="entry-"] time'), eachHasDigit: false);
    $page->assertNoJavaScriptErrors();
});
