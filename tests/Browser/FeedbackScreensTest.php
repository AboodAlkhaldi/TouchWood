<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Feedback's Support screens in a real browser (feedback.md §4.1 F1–F3, F9). The feature tests beside
| these prove what the pages are handed; these prove a person can use them: two waiting reviews ticked
| and approved at once, a ready reason added from its dialog, and every Support page at a phone's width
| with nothing scrolling sideways.
|
| Deliberately no RefreshDatabase, as the other browser tests: every row here has a name of its own,
| from the clock.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CatalogProducts::numberFrom((int) feedbackScreenTag());
});

function feedbackScreenTag(): string
{
    return substr((string) hrtime(true), -8);
}

function feedbackScreenPanel(): mixed
{
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    return $page;
}

it('approves two ticked reviews at once', function () {
    $sold = Fb::sold();
    $ids = array_map(static fn (string $variant): string => Fb::reviewRow($sold['product'], $variant, Fx::customer(strtolower((string) Str::ulid()).'@example.test')), $sold['variants']);
    $page = feedbackScreenPanel();

    $page->navigate('/admin/reviews?store=sa', BROWSER_PAGE_LOAD)
        ->assertSee('Reviews')
        ->click("[data-test=\"select-{$ids[0]}\"]")
        ->click("[data-test=\"select-{$ids[1]}\"]")
        ->assertSee('Selected: 2')
        // Cancelling the reject dialog keeps the ticks.
        ->click('[data-test="bulk-reject"]')
        ->click('[data-test="modal-cancel"]')
        ->assertSee('Selected: 2')
        ->click('[data-test="bulk-approve"]');

    expect(browserUntil($page, "document.body.innerText.includes('Done: 2.')"))->toBeTrue()
        ->and(DB::table('feedback.reviews')->whereIn('id', $ids)->pluck('status')->unique()->values()->all())->toBe(['APPROVED']);
});

it('adds a ready reason from its dialog, and draws every Support page at a phone\'s width', function () {
    $reason = 'Off topic '.feedbackScreenTag();
    $page = feedbackScreenPanel();

    $page->navigate('/admin/rejection-reasons', BROWSER_PAGE_LOAD)
        ->assertSee('Rejection Reasons')
        ->click('[data-test="add-reason"]')
        ->type('#reason-text-ar', 'خارج الموضوع')
        ->type('#reason-text-en', $reason)
        ->click('[data-test="confirm-reason"]');

    expect(browserUntil($page, "document.body.innerText.includes('Reason added.')"))->toBeTrue();
    $page->assertSee($reason);

    // The ⋯ menu speaks the sortable list's words, not their keys.
    $page->click('[data-test="reason-0"] [data-test="reason-actions"]')
        ->assertSee('Move to Top')
        ->assertDontSee('catalog::admin')
        ->keys('[role="menu"]', ['Escape']);

    $page->resize(375, 812);

    foreach (['/admin/reviews?store=sa', '/admin/questions?store=sa', '/admin/favourites?store=sa', '/admin/rejection-reasons'] as $uri) {
        $page->navigate($uri, BROWSER_PAGE_LOAD);
        $wide = (string) $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth ? String(document.documentElement.scrollWidth) : ""');

        expect($wide)->toBe('', "{$uri} scrolls sideways");
    }
});
