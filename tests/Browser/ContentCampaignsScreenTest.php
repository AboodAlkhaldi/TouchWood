<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaign;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaignHandler;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;

use function Pest\Laravel\seed;

/*
| W4b in a real browser (content.md §4.4, §1.7 #15): a draft campaign of two stores scheduled from its page - the
| question naming both stores, All Stores - and, in Arabic, one store's run alone, which leaves the campaign Mixed with
| each store's state beside it; both at a phone's 375 x 812, in Latin digits, its prices and theme drawn once mounted.
|
| Deliberately no RefreshDatabase: each run makes its own campaign, named by its run's number (lesson 174), with no
| prices, so nothing of Pricing's is touched (a campaign of no prices schedules, §1.7 #16).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A draft campaign of these stores, written through its use case as the system, its id.
 *
 * @param  list<string>  $codes
 */
function contentCampaignsScreenDraft(string $run, array $codes): string
{
    $typed = static fn (int $days): string => CarbonImmutable::now()->addDays($days)->setTimezone('Asia/Riyadh')->format('Y-m-d 00:00');

    return Fx::asSystem(fn (): string => app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, "عروض {$run}", "Offers {$run}", $typed(30), $typed(40), array_map(Fx::storeId(...), $codes))));
}

it('schedules a two-store campaign from its page, the question naming both stores', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::PRICING_PRICE_EDIT], 'en', ['sa', 'ae']);
    $page->resize(375, 812);
    $campaign = contentCampaignsScreenDraft($run, ['sa', 'ae']);
    $names = array_map(static fn (string $code): string => (string) DB::table('platform.stores')->where('code', $code)->value('name->en'), ['sa', 'ae']);

    $page->navigate("/admin/content/campaigns/{$campaign}", BROWSER_SLOW_PAGE_LOAD);
    // The prices tabs and the theme's search load once the page is mounted.
    expect(browserSoon($page, "document.querySelector('[data-test=\"campaign-form\"]') !== null && document.querySelector('[data-test=\"prices-sa\"]') !== null && document.querySelector('[data-test=\"theme-card\"] button[role=\"combobox\"]') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    $page->click('[data-test="schedule"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"schedule-dialog-confirm\"]') !== null"))->toBeTrue()
        ->and($page->script("(() => { const text = document.querySelector('[role=\"dialog\"], [role=\"alertdialog\"]').textContent; return text.includes('{$names[0]}') && text.includes('{$names[1]}'); })()"))->toBeTrue();

    $page->click('[data-test="schedule-dialog-confirm"]');
    expect(browserSoon($page, "document.body.textContent.includes('Campaign scheduled') && document.querySelector('[data-test=\"campaign-state\"] [data-test=\"state\"]')?.textContent === 'Scheduled'"))->toBeTrue()
        ->and(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->pluck('state')->unique()->values()->all())->toBe(['SCHEDULED'])
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    // The list names both stores with their states; on a phone each row is a card opening the campaign.
    $page->navigate('/admin/content/campaigns?store=sa', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"campaign-card-{$campaign}\"]')?.textContent.includes('Offers {$run}') === true"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});

it('waits to schedule while a store\'s prices are typed and not saved, then schedules once they are', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::PRICING_PRICE_EDIT], 'en', ['sa', 'ae']);
    $page->resize(375, 812);
    $campaign = contentCampaignsScreenDraft($run, ['sa', 'ae']);

    $page->navigate("/admin/content/campaigns/{$campaign}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"prices-sa\"] [data-test=\"line-RETAIL\"]') !== null"))->toBeTrue();

    // A change in a store's Prices tab, not saved: Schedule would hand Pricing what was saved instead (PR 8's review).
    $page->click('[data-test="prices-sa"] [data-test="line-RETAIL"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"schedule\"]')?.getAttribute('aria-disabled') === 'true' && document.body.textContent.includes('Save the prices typed in')"))->toBeTrue();
    // Pressed all the same (Playwright clicks nothing out of reach, lesson 199): no question, nothing scheduled.
    $page->script("document.querySelector('[data-test=\"schedule\"]').click()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"schedule-dialog-confirm\"]') === null"))->toBeTrue()
        ->and(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->pluck('state')->unique()->values()->all())->toBe(['DRAFT']);

    $page->click('[data-test="prices-sa"] [data-test="save-prices"]');
    expect(browserSoon($page, "document.body.textContent.includes('Prices saved') && document.querySelector('[data-test=\"schedule\"]')?.getAttribute('aria-disabled') !== 'true'"))->toBeTrue();
    $page->click('[data-test="schedule"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"schedule-dialog-confirm\"]') !== null"))->toBeTrue();
    $page->click('[data-test="schedule-dialog-confirm"]');
    expect(browserSoon($page, "document.body.textContent.includes('Campaign scheduled')"))->toBeTrue()
        ->and(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->where('store_id', Fx::storeId('sa'))->value('state'))->toBe('SCHEDULED')
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});

it('keeps a date typed and not saved through a store\'s prices saved, so Schedule saves it first', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::PRICING_PRICE_EDIT], 'en', ['sa', 'ae']);
    $page->resize(375, 812);
    $campaign = contentCampaignsScreenDraft($run, ['sa', 'ae']);
    $ends = substr((string) DB::table('content.campaigns')->where('id', $campaign)->value('ends_at_local'), 0, 10);

    $page->navigate("/admin/content/campaigns/{$campaign}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"prices-sa\"] [data-test=\"line-RETAIL\"]') !== null && document.querySelector('#ends-time') !== null"))->toBeTrue();

    // A new end typed, not saved; then a store's prices saved, which raises the campaign's version (PR 8's second
    // review): the end is still the form's change, so Schedule saves it before scheduling.
    $page->type('#ends-time', '18:30');
    $page->click('[data-test="prices-sa"] [data-test="line-RETAIL"]');
    $page->click('[data-test="prices-sa"] [data-test="save-prices"]');
    expect(browserSoon($page, "document.body.textContent.includes('Prices saved') && document.querySelector('[data-test=\"schedule\"]')?.getAttribute('aria-disabled') !== 'true'"))->toBeTrue()
        ->and($page->script("document.querySelector('#ends-time').value"))->toBe('18:30');

    $page->click('[data-test="schedule"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"schedule-dialog-confirm\"]') !== null"))->toBeTrue();
    $page->click('[data-test="schedule-dialog-confirm"]');
    expect(browserSoon($page, "document.body.textContent.includes('Campaign scheduled')"))->toBeTrue()
        ->and(substr((string) DB::table('content.campaigns')->where('id', $campaign)->value('ends_at_local'), 0, 16))->toBe("{$ends} 18:30")
        ->and(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->pluck('state')->unique()->values()->all())->toBe(['SCHEDULED'])
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});

it('schedules one store\'s run in Arabic, leaving the campaign Mixed with each store\'s state', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::PRICING_PRICE_EDIT], 'ar', ['sa', 'ae']);
    $page->resize(375, 812);
    $campaign = contentCampaignsScreenDraft($run, ['sa', 'ae']);
    $ae = Fx::storeId('ae');

    $page->navigate("/admin/content/campaigns/{$campaign}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"prices-ae\"]') !== null"))->toBeTrue();

    $page->click('[data-test="schedule"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"schedule-dialog-scope-{$ae}\"]') !== null"))->toBeTrue();
    $page->click("[data-test=\"schedule-dialog-scope-{$ae}\"]");
    $name = (string) DB::table('platform.stores')->where('code', 'ae')->value('name->ar');
    // The sentence names the one store chosen.
    expect(browserSoon($page, "document.querySelector('[role=\"dialog\"], [role=\"alertdialog\"]').textContent.includes('{$name}')"))->toBeTrue();
    $page->click('[data-test="schedule-dialog-confirm"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"campaign-state\"] [data-test=\"state\"]')?.textContent === 'مختلط' && document.querySelector('[data-test=\"run-state-ae\"]') !== null"))->toBeTrue()
        ->and(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->where('store_id', $ae)->value('state'))->toBe('SCHEDULED')
        ->and(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->where('store_id', Fx::storeId('sa'))->value('state'))->toBe('DRAFT')
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});
