<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Public\Contracts\CampaignNotices;
use Modules\Content\Public\Dto\NoticeChoiceDto;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentScreen as Screen;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| W4 · Campaigns and W4b over HTTP (content.md §4.4, §1.7): the list by its run's state with each tab's count and the
| Mixed badge; New Campaign saved; a campaign's page with each store's run - prices as the form edits them, Pricing's
| right, the ceiling, the notice and the store's drafts; prices set, previewed and searched; prices fixed once a run's
| start has passed whatever its state; Translate's mark (P14) kept on a draft and asked for at Schedule; the notice
| chosen, one gone stale said; Cancel and End by store; Delete; who is refused; the menu entry; the queries. The rules
| are CampaignsTest's; a schedule stopped part way is ScheduleRefusalAnswerTest's.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subHour()->startOfSecond());
    Carbon::setTestNow(CarbonImmutable::now());
    campaignsScreenNotices([]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

const CAMPAIGNS_SCREEN = '/admin/content/campaigns';

/**
 * @param  list<string>  $stores
 */
function campaignsScreenEditor(array $stores = ['sa'], bool $pricing = true): string
{
    return Fx::staffWith([ContentPermissions::CAMPAIGN_MANAGE, ...($pricing ? [ContentPermissions::PRICING_PRICE_EDIT] : [])], $stores, RoleLevel::Admin);
}

/** A moment as typed for every store: so many minutes from now on the first store's clock. */
function campaignsScreenTyped(int $minutes): string
{
    return CarbonImmutable::now()->addMinutes($minutes)->setTimezone('Asia/Riyadh')->format('Y-m-d H:i');
}

/**
 * @param  list<string>  $stores  codes
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function campaignsScreenFields(array $stores = ['sa'], array $with = []): array
{
    return [
        'version' => 0, 'name_ar' => 'عروض الربيع', 'name_en' => 'Spring Offers', 'stores' => array_map(Fx::storeId(...), $stores),
        'starts_at' => campaignsScreenTyped(60 * 24), 'ends_at' => campaignsScreenTyped(60 * 24 * 6), 'theme' => '', 'intent' => 'draft', ...$with,
    ];
}

/**
 * A new campaign saved over HTTP, its id.
 *
 * @param  list<string>  $stores
 * @param  array<string, mixed>  $with
 */
function campaignsScreenCreate(AdminBrowser $browser, array $stores = ['sa'], array $with = []): string
{
    $browser->post(CAMPAIGNS_SCREEN, campaignsScreenFields($stores, $with), Screen::fromPage(CAMPAIGNS_SCREEN.'/new'))->assertRedirect();

    return (string) DB::table('content.campaigns')->orderByDesc('created_at')->orderByDesc('id')->value('id');
}

function campaignsScreenVersion(string $campaign): int
{
    return (int) DB::table('content.campaigns')->where('id', $campaign)->value('version');
}

function campaignsScreenRun(string $campaign, string $code = 'sa'): string
{
    return (string) DB::table('content.campaign_runs')->where('campaign_id', $campaign)->where('store_id', Fx::storeId($code))->value('id');
}

/**
 * Ops' side of the port, stood in: these notices, by store code.
 *
 * @param  array<string, list<NoticeChoiceDto>>  $byStore
 */
function campaignsScreenNotices(array $byStore): void
{
    $stores = [];

    foreach ($byStore as $code => $notices) {
        $stores[Fx::storeId($code)] = $notices;
    }

    app()->instance(CampaignNotices::class, new class($stores) implements CampaignNotices
    {
        /** @param array<string, list<NoticeChoiceDto>> $stores */
        public function __construct(private array $stores) {}

        public function drafts(StoreId $store, ?string $after, int $limit): array
        {
            return array_values(array_filter($this->stores[$store->value] ?? [], static fn (NoticeChoiceDto $notice): bool => $notice->isDraft()));
        }

        public function named(StoreId $store, array $ids): array
        {
            return array_values(array_filter($this->stores[$store->value] ?? [], static fn (NoticeChoiceDto $notice): bool => in_array($notice->id, $ids, true)));
        }
    });
}

it('lists a store\'s campaigns by its run\'s state, with each tab\'s count, every store\'s state and Mixed', function () {
    $browser = Screen::as(campaignsScreenEditor(['sa', 'ae']));
    $both = campaignsScreenCreate($browser, ['sa', 'ae']);
    $browser->post(CAMPAIGNS_SCREEN."/{$both}/schedule", ['version' => campaignsScreenVersion($both), 'store' => Fx::storeId('sa')], Screen::fromPage(CAMPAIGNS_SCREEN."/{$both}"))->assertRedirect();
    campaignsScreenCreate($browser, ['ae']);

    $browser->get(CAMPAIGNS_SCREEN.'?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Campaigns/Index')
        ->where('storeCode', 'sa')
        ->where('tab', 'all')
        ->where('counts', ['all' => 1, 'scheduled' => 1, 'live' => 0, 'ended' => 0])
        ->has('campaigns', 1)
        ->where('campaigns.0.id', $both)
        ->where('campaigns.0.state', 'SCHEDULED')
        ->where('campaigns.0.overall', 'MIXED')
        ->has('campaigns.0.stores', 2)
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_campaigns.title') && $words->has('content::admin_shared.state.MIXED')));

    // The live tab is empty in this store; the other store's draft is its own.
    $browser->get(CAMPAIGNS_SCREEN.'?store=sa&tab=live')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'live')->has('campaigns', 0));
    $browser->get(CAMPAIGNS_SCREEN.'?store=ae')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('campaigns', 2)->where('counts.all', 2));

    expect(array_keys($browser->get(CAMPAIGNS_SCREEN.'?store=sa', Screen::JSON)->assertOk()->json()))->toBe(['campaigns', 'next']);
});

it('saves a new campaign as a draft and opens its page with each store\'s run, the ceiling and the store\'s drafts', function () {
    $draft = strtolower((string) Str::ulid());
    campaignsScreenNotices(['sa' => [new NoticeChoiceDto($draft, 'بدأت العروض', 'Offers started', 'DRAFT')]]);
    $browser = Screen::as(campaignsScreenEditor(['sa', 'ae']));

    $browser->get(CAMPAIGNS_SCREEN.'/new')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Campaigns/Form')
        ->where('campaign', null)
        ->has('stores', 2)
        ->where('mayOpenThemes', false));

    $saved = $browser->post(CAMPAIGNS_SCREEN, campaignsScreenFields(['sa', 'ae']), Screen::fromPage(CAMPAIGNS_SCREEN.'/new'));
    $id = (string) DB::table('content.campaigns')->value('id');
    $saved->assertRedirect(CAMPAIGNS_SCREEN."/{$id}");

    expect(AdminBrowser::flashed($saved, 'status'))->toBe(__('content::admin_campaigns.saved'));

    $browser->get(CAMPAIGNS_SCREEN."/{$id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Campaigns/Form')
        ->where('campaign.id', $id)
        ->where('campaign.state', 'DRAFT')
        ->where('campaign.halfLive', false)
        ->has('runs', 2)
        ->where('runs.0.state', 'DRAFT')
        ->where('runs.0.pricesOpen', true)
        ->where('runs.0.mayPrice', true)
        ->where('runs.0.prices', null)
        ->where('runs.0.ceilingPercent', 30)
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_themes.parts.name.HERO')));

    $runs = $browser->get(CAMPAIGNS_SCREEN."/{$id}")->viewData('page')['props']['runs'];
    $sa = collect($runs)->firstWhere('store.code', 'sa');

    expect($sa['drafts'])->toBe([['id' => $draft, 'titleAr' => 'بدأت العروض', 'titleEn' => 'Offers started', 'status' => 'DRAFT']]);

    // Arabic is required to save: refused under its box, nothing written.
    $refused = $browser->post(CAMPAIGNS_SCREEN, campaignsScreenFields(['sa'], ['name_ar' => '']), Screen::fromPage(CAMPAIGNS_SCREEN.'/new'));
    expect(Screen::errors($refused))->toHaveKey('name_ar')
        ->and(DB::table('content.campaigns')->count())->toBe(1);
});

it('sets a draft\'s prices, previews them live, searches what they may name, and shows them by name', function () {
    $product = Px::ready(['60 cm', '80 cm']);
    Rx::set('sa', [$product['variants'][0] => '100', $product['variants'][1] => '200']);
    // Sold in the store: the search finds what the store sells.
    Rx::switchOn('sa', $product['variants']);
    $browser = Screen::as(campaignsScreenEditor());
    $campaign = campaignsScreenCreate($browser);
    $run = campaignsScreenRun($campaign);
    $prices = ['lines' => 'BOTH', 'targets' => [['kind' => 'PRODUCT', 'id' => $product['product'], 'percentBp' => 1000]], 'leftOut' => []];

    $preview = $browser->get(CAMPAIGNS_SCREEN.'/preview?'.http_build_query(['run' => $run, 'prices' => json_encode($prices)]), Screen::JSON)->assertOk();

    expect($preview->json('reachedProducts'))->toBe(1)
        ->and($preview->json('reachedSizes'))->toBe(2)
        ->and($preview->json('deepestBasisPoints'))->toBe(1000)
        ->and($preview->json('products.0.sizes.0.newPrice'))->toBe('90.00')
        ->and($preview->json('currency'))->toBe('SAR');

    $found = $browser->get(CAMPAIGNS_SCREEN.'/targets?'.http_build_query(['run' => $run, 'kind' => 'PRODUCT', 'q' => 'Drawer']), Screen::JSON)->assertOk();
    expect($found->json('targets.0.id'))->toBe($product['product']);

    $saved = $browser->post(CAMPAIGNS_SCREEN."/runs/{$run}/prices", ['version' => campaignsScreenVersion($campaign), 'prices' => $prices], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();
    expect(AdminBrowser::flashed($saved, 'status'))->toBe(__('content::admin_campaigns.prices_saved'));

    $browser->get(CAMPAIGNS_SCREEN."/{$campaign}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('runs.0.prices.lines', 'BOTH')
        ->where('runs.0.prices.targets.0.kind', 'PRODUCT')
        ->where('runs.0.prices.targets.0.nameEn', fn (string $name): bool => str_starts_with($name, 'Drawer'))
        ->where('runs.0.prices.targets.0.percentBp', 1000)
        ->where('runs.0.prices.targets.0.archived', false));

    // A stale version is refused at the top, and what Pricing holds is untouched (a draft: nothing).
    expect(Screen::errors($browser->post(CAMPAIGNS_SCREEN."/runs/{$run}/prices", ['version' => 1, 'prices' => $prices], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))))->toHaveKey('form');
});

it('shows a store\'s prices read-only to someone without Pricing\'s right there, and refuses them', function () {
    $product = Px::ready();
    $browser = Screen::as(campaignsScreenEditor(['sa'], pricing: false));
    $campaign = campaignsScreenCreate($browser);
    $run = campaignsScreenRun($campaign);

    $browser->get(CAMPAIGNS_SCREEN."/{$campaign}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('runs.0.mayPrice', false));

    $refused = $browser->post(CAMPAIGNS_SCREEN."/runs/{$run}/prices", ['version' => campaignsScreenVersion($campaign), 'prices' => ['lines' => 'BOTH', 'targets' => [['kind' => 'PRODUCT', 'id' => $product['product'], 'percentBp' => 1000]], 'leftOut' => []]], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"));

    expect(Screen::errors($refused))->toHaveKey('form')
        ->and(DB::table('content.campaign_runs')->where('id', $run)->value('price_choices'))->toBeNull();
});

it('fixes a scheduled run\'s prices once its start has passed, whatever its state says', function () {
    $product = Px::ready();
    Rx::set('sa', [$product['variants'][0] => '100']);
    $browser = Screen::as(campaignsScreenEditor());
    $campaign = campaignsScreenCreate($browser, ['sa'], ['starts_at' => campaignsScreenTyped(10), 'ends_at' => campaignsScreenTyped(60 * 24)]);
    $run = campaignsScreenRun($campaign);
    $prices = ['lines' => 'BOTH', 'targets' => [['kind' => 'PRODUCT', 'id' => $product['product'], 'percentBp' => 1000]], 'leftOut' => []];
    $browser->post(CAMPAIGNS_SCREEN."/runs/{$run}/prices", ['version' => campaignsScreenVersion($campaign), 'prices' => $prices], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();
    $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/schedule", ['version' => campaignsScreenVersion($campaign)], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();

    $browser->get(CAMPAIGNS_SCREEN."/{$campaign}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('runs.0.state', 'SCHEDULED')
        ->where('runs.0.pricesOpen', true)
        // Pricing holds them now, and the form reads them from it (§1.7 #14).
        ->where('runs.0.prices.targets.0.percentBp', 1000));

    // Its start passes; the job has not run, so Content still says scheduled.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(20));
    Carbon::setTestNow(CarbonImmutable::now());

    $browser->get(CAMPAIGNS_SCREEN."/{$campaign}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('runs.0.state', 'SCHEDULED')
        ->where('runs.0.pricesOpen', false));

    expect(Screen::errors($browser->post(CAMPAIGNS_SCREEN."/runs/{$run}/prices", ['version' => campaignsScreenVersion($campaign), 'prices' => $prices], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))))->toHaveKey('form');
});

it('keeps Translate\'s mark on a draft and asks for the tick at Schedule, which clears it (P14)', function () {
    $browser = Screen::as(campaignsScreenEditor());
    $campaign = campaignsScreenCreate($browser, ['sa'], ['translated' => true]);
    $marked = fn (): bool => DB::table('content.unread_translations')->where('kind', 'CAMPAIGN')->where('item_id', $campaign)->exists();

    expect($marked())->toBeTrue();
    $browser->get(CAMPAIGNS_SCREEN."/{$campaign}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('campaign.translationUnread', true));

    // Schedule without the tick: refused at the top, nothing scheduled.
    $refused = $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/schedule", ['version' => campaignsScreenVersion($campaign)], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"));
    expect(Screen::errors($refused))->toHaveKey('form')
        ->and(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->value('state'))->toBe('DRAFT');

    $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/schedule", ['version' => campaignsScreenVersion($campaign), 'read' => true], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();

    expect(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->value('state'))->toBe('SCHEDULED')
        ->and($marked())->toBeFalse();

    // Scheduled now: a save with unread English asks for the tick as Schedule would.
    $again = $browser->post(CAMPAIGNS_SCREEN."/{$campaign}", campaignsScreenFields(['sa'], ['version' => campaignsScreenVersion($campaign), 'starts_at' => substr((string) DB::table('content.campaigns')->where('id', $campaign)->value('starts_at_local'), 0, 16), 'ends_at' => substr((string) DB::table('content.campaigns')->where('id', $campaign)->value('ends_at_local'), 0, 16), 'translated' => true]), Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"));
    expect(Screen::errors($again))->toHaveKey('form')
        ->and($marked())->toBeFalse();
});

it('takes a draft campaign\'s mark with it when it is deleted', function () {
    $browser = Screen::as(campaignsScreenEditor());
    $campaign = campaignsScreenCreate($browser, ['sa'], ['translated' => true]);

    $deleted = $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/delete", ['version' => campaignsScreenVersion($campaign)], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect(CAMPAIGNS_SCREEN);

    expect(AdminBrowser::flashed($deleted, 'status'))->toBe(__('content::admin_campaigns.deleted'))
        ->and(DB::table('content.campaigns')->where('id', $campaign)->exists())->toBeFalse()
        ->and(DB::table('content.unread_translations')->where('item_id', $campaign)->exists())->toBeFalse();
});

it('chooses a store\'s draft notice, and says when the one chosen is a draft no more', function () {
    $notice = strtolower((string) Str::ulid());
    campaignsScreenNotices(['sa' => [new NoticeChoiceDto($notice, 'بدأت', 'Started', 'DRAFT')]]);
    $browser = Screen::as(campaignsScreenEditor());
    $campaign = campaignsScreenCreate($browser);
    $run = campaignsScreenRun($campaign);

    $chosen = $browser->post(CAMPAIGNS_SCREEN."/runs/{$run}/notice", ['version' => campaignsScreenVersion($campaign), 'notice' => $notice], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();
    expect(AdminBrowser::flashed($chosen, 'status'))->toBe(__('content::admin_campaigns.notice_saved'))
        ->and(DB::table('content.campaign_runs')->where('id', $run)->value('notice_id'))->toBe($notice);

    // Sent from Ops meanwhile: the page says so, and offers no draft.
    campaignsScreenNotices(['sa' => [new NoticeChoiceDto($notice, 'بدأت', 'Started', 'SENT')]]);
    $browser->get(CAMPAIGNS_SCREEN."/{$campaign}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('runs.0.notice.status', 'SENT')
        ->has('runs.0.drafts', 0));
});

it('cancels one store\'s scheduled run and ends another\'s live run, each naming its store', function () {
    $browser = Screen::as(campaignsScreenEditor(['sa', 'ae']));
    $campaign = campaignsScreenCreate($browser, ['sa', 'ae'], ['starts_at' => campaignsScreenTyped(10), 'ends_at' => campaignsScreenTyped(60 * 24)]);
    $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/schedule", ['version' => campaignsScreenVersion($campaign)], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();

    $cancelled = $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/cancel", ['store' => Fx::storeId('ae')], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();
    expect(AdminBrowser::flashed($cancelled, 'status'))->toBe(__('content::admin_campaigns.cancelled'))
        ->and(DB::table('content.campaign_runs')->where('id', campaignsScreenRun($campaign, 'ae'))->value('state'))->toBe('CANCELLED')
        ->and(DB::table('content.campaign_runs')->where('id', campaignsScreenRun($campaign, 'sa'))->value('state'))->toBe('SCHEDULED');

    // The list's Ended tab holds a cancelled run too (§9.3e E1).
    $browser->get(CAMPAIGNS_SCREEN.'?store=ae&tab=ended')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('counts.ended', 1)
        ->where('campaigns.0.state', 'CANCELLED'));

    // End refuses a run that is not live, at the top.
    expect(Screen::errors($browser->post(CAMPAIGNS_SCREEN."/{$campaign}/end", ['store' => Fx::storeId('sa')], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))))->toHaveKey('form');

    DB::table('content.campaign_runs')->where('id', campaignsScreenRun($campaign, 'sa'))->update(['state' => 'LIVE', 'went_live_at' => CarbonImmutable::now()]);
    $browser->get(CAMPAIGNS_SCREEN."/{$campaign}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('campaign.halfLive', true)->where('campaign.state', 'MIXED'));

    $ended = $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/end", ['store' => Fx::storeId('sa')], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();
    expect(AdminBrowser::flashed($ended, 'status'))->toBe(__('content::admin_campaigns.ended'))
        ->and(DB::table('content.campaign_runs')->where('id', campaignsScreenRun($campaign, 'sa'))->value('state'))->toBe('ENDED');
});

it('refuses the list without content.campaign.manage, and another store\'s campaign is not found', function () {
    $browser = Screen::as(campaignsScreenEditor(['ae']));
    $theirs = campaignsScreenCreate($browser, ['ae']);

    Screen::as(Fx::staffWith([ContentPermissions::BAR_MANAGE], ['sa']))->get(CAMPAIGNS_SCREEN)->assertForbidden();
    Screen::as(campaignsScreenEditor(['sa']))->get(CAMPAIGNS_SCREEN."/{$theirs}")->assertNotFound();
    Screen::as(campaignsScreenEditor(['sa']))->get(CAMPAIGNS_SCREEN.'/targets?'.http_build_query(['run' => campaignsScreenRun($theirs, 'ae'), 'kind' => 'BRAND']), Screen::JSON)->assertNotFound();
});

it('offers the menu entry to whoever may run campaigns, and Themes to a Super Admin', function () {
    expect(Screen::menu(Screen::as(campaignsScreenEditor())))->toBe(['campaigns'])
        ->and(Screen::menu(Screen::as(Fx::staff(superAdmin: true))))->toContain('campaigns', 'themes');
});

it('costs few queries of its own', function () {
    $browser = Screen::as(campaignsScreenEditor(['sa', 'ae']));
    $campaign = campaignsScreenCreate($browser, ['sa', 'ae']);

    // Measured 2026-11-11 (PR 8), on a reader who also holds Pricing's right: its frame is 19 - the panel's 10
    // (AdminFrameQueryBudgetTest), the bell's, and Pricing's Needs a Price count in the menu (8, Pricing's own). The list
    // 22, 3 of its own: the tabs' counts, the page, its runs' stores (no theme or notice to name here). A campaign's page
    // of two stores 23, 4 of its own and 2 cache reads (the ceiling setting): the campaign and its runs, the themes on
    // offer, Translate's mark - and with Ops' own port one more a store for its draft notices (25, measured by a probe;
    // stood in here). Content's target is 15 of its own (§4.2). Pricing's preview is not among them: the page asks it as
    // the prices are typed, a store at a time.
    expect([Screen::queries($browser, CAMPAIGNS_SCREEN.'?store=sa'), Screen::queries($browser, CAMPAIGNS_SCREEN."/{$campaign}")])->toBe([22, 23]);
});
