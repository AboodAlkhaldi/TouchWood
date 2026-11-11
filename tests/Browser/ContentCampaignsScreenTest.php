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

/** A draft campaign of these stores, written through its use case as the system, its id. */
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
