<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Feedback\Application\FeedbackPermissions;
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
    // Before signing in: made after it, the product's fixtures left the panel's session signed out.
    feedbackScreenFavourite();
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

    // On a phone each favourited product is a card (round 3 #15).
    $page->navigate('/admin/favourites?store=sa', BROWSER_PAGE_LOAD);
    expect(browserRowIsCard($page, FEEDBACK_SCREEN_FIRST_CARD, FEEDBACK_SCREEN_FIRST_ROW))->toBeTrue((string) $page->script('location.pathname'));
});

/*
| The list's first card and first table row. The saves of every run are kept and the list puts the most saved first, so
| which product leads is the list's own business: any card drawn, and the table's rows not, is what is checked.
*/
const FEEDBACK_SCREEN_FIRST_CARD = '[data-test="favourite-cards"] [data-test^="favourite-card-"]';
const FEEDBACK_SCREEN_FIRST_ROW = 'tr[data-test^="favourite-"]';

/** A product saved in `sa`, so Favourited Products has at least one line. */
function feedbackScreenFavourite(): void
{
    DB::table('feedback.wishlist_items')->insert(['customer_id' => Fx::customer(strtolower((string) Str::ulid()).'@example.test'), 'product_id' => Fb::sold()['product'], 'store_id' => Fx::storeId('sa'), 'saved_at' => now()]);
}

it('draws Favourited Products as cards on a phone in Arabic (round 3 #15)', function () {
    feedbackScreenFavourite();
    $staffId = Fx::staffWith([FeedbackPermissions::WISHLIST_VIEW], ['sa'], RoleLevel::Admin);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'ar']);

    $page = visit('/admin/sign-in')
        ->type('#email', (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->resize(375, 812);
    $page->navigate('/admin/favourites?store=sa', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl'"))->toBeTrue()
        ->and(browserRowIsCard($page, FEEDBACK_SCREEN_FIRST_CARD, FEEDBACK_SCREEN_FIRST_ROW))->toBeTrue()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
