<?php

declare(strict_types=1);

use App\Http\FormErrors;
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
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Public\Contracts\CampaignNotices;
use Modules\Content\Public\Dto\NoticeChoiceDto;
use Shared\Application\Unauthorized;
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

    /** @var list<array<string, mixed>> $runs */
    $runs = $browser->get(CAMPAIGNS_SCREEN."/{$id}")->viewData('page')['props']['runs'];
    $sa = collect($runs)->firstWhere('store.code', 'sa');

    expect($sa['drafts'] ?? null)->toBe([['id' => $draft, 'titleAr' => 'بدأت العروض', 'titleEn' => 'Offers started', 'status' => 'DRAFT']]);

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

it('shows per store on the list how many sizes each run prices, the count its preview gives (W4\'s Offers)', function () {
    $product = Px::ready(['60 cm', '80 cm', '100 cm']);
    Rx::set('sa', array_fill_keys($product['variants'], '100'));
    Rx::switchOn('sa', $product['variants']);
    $browser = Screen::as(campaignsScreenEditor(['sa', 'ae']));
    $campaign = campaignsScreenCreate($browser, ['sa', 'ae']);
    $run = campaignsScreenRun($campaign);
    $prices = ['lines' => 'BOTH', 'targets' => [['kind' => 'PRODUCT', 'id' => $product['product'], 'percentBp' => 1000]], 'leftOut' => []];
    $browser->post(CAMPAIGNS_SCREEN."/runs/{$run}/prices", ['version' => campaignsScreenVersion($campaign), 'prices' => $prices], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();
    $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/schedule", ['version' => campaignsScreenVersion($campaign), 'store' => Fx::storeId('sa')], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();
    $preview = $browser->get(CAMPAIGNS_SCREEN.'/preview?'.http_build_query(['run' => $run, 'prices' => json_encode($prices)]), Screen::JSON)->assertOk();

    $browser->get(CAMPAIGNS_SCREEN.'?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('campaigns.0.stores', fn (Collection $stores): bool => $stores->firstWhere('code', 'sa')['offers'] === $preview->json('reachedSizes')
            && $preview->json('reachedSizes') === 3
            // The other store's run is a draft: Pricing holds nothing of it yet.
            && $stores->firstWhere('code', 'ae')['offers'] === null));
});

it('counts 0 for a scheduled run that prices no size, and keeps the dash for a draft\'s run alone (W4\'s Offers)', function () {
    $product = Px::ready(['60 cm']);
    Rx::set('sa', array_fill_keys($product['variants'], '100'));
    Rx::switchOn('sa', $product['variants']);
    $browser = Screen::as(campaignsScreenEditor(['sa']));
    $campaign = campaignsScreenCreate($browser, ['sa']);
    $run = campaignsScreenRun($campaign);
    // A fixed price at the retail price is no campaign price (pricing.md §9.6 #7): the run prices nothing.
    $browser->post(CAMPAIGNS_SCREEN."/runs/{$run}/prices", ['version' => campaignsScreenVersion($campaign), 'prices' => ['lines' => 'BOTH', 'targets' => [['kind' => 'SIZE', 'id' => $product['variants'][0], 'amountMinor' => 10000]], 'leftOut' => []]], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect()->assertSessionHasNoErrors();
    $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/schedule", ['version' => campaignsScreenVersion($campaign), 'store' => Fx::storeId('sa')], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect()->assertSessionHasNoErrors();

    expect(DB::table('content.campaign_runs')->where('id', $run)->value('state'))->toBe('SCHEDULED');

    $browser->get(CAMPAIGNS_SCREEN.'?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('campaigns.0.stores.0.offers', 0));
});

it('lists only the campaigns the reader may open: one with a store they hold no job in is left out', function () {
    $both = Screen::as(campaignsScreenEditor(['sa', 'ae']));
    campaignsScreenCreate($both, ['sa', 'ae']);
    $mine = campaignsScreenCreate($both, ['sa']);

    Screen::as(campaignsScreenEditor(['sa']))->get(CAMPAIGNS_SCREEN.'?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('campaigns', 1)
        ->where('campaigns.0.id', $mine)
        ->where('counts.all', 1));
});

it('says a save stands when the Schedule of the same press is refused, with the tick owed (P14)', function () {
    $browser = Screen::as(campaignsScreenEditor());
    $campaign = campaignsScreenCreate($browser, ['sa'], ['translated' => true]);

    $refused = $browser->post(CAMPAIGNS_SCREEN."/{$campaign}", campaignsScreenFields(['sa'], [
        'version' => campaignsScreenVersion($campaign), 'name_en' => 'Spring Offers Again', 'intent' => 'schedule',
        'starts_at' => substr((string) DB::table('content.campaigns')->where('id', $campaign)->value('starts_at_local'), 0, 16),
        'ends_at' => substr((string) DB::table('content.campaigns')->where('id', $campaign)->value('ends_at_local'), 0, 16),
    ]), Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"));

    expect(Screen::errors($refused))->toHaveKey('form')
        ->and(AdminBrowser::flashed($refused, 'status'))->toBe(__('content::admin_campaigns.saved_not_scheduled'))
        ->and(DB::table('content.campaigns')->where('id', $campaign)->value('name_en'))->toBe('Spring Offers Again')
        ->and(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->value('state'))->toBe('DRAFT');
});

it('says a save stands when Pricing refuses the Schedule of the same press, marking the sizes in that store alone', function () {
    $product = Px::ready(['60 cm']);
    $variant = $product['variants'][0];
    Rx::set('sa', [$variant => '100']);
    $browser = Screen::as(campaignsScreenEditor());
    $campaign = campaignsScreenCreate($browser);
    $run = campaignsScreenRun($campaign);
    // A fixed 10.00 on a size priced 100.00: past the ceiling of 30%, which Pricing refuses naming the size.
    $browser->post(CAMPAIGNS_SCREEN."/runs/{$run}/prices", ['version' => campaignsScreenVersion($campaign), 'prices' => ['lines' => 'BOTH', 'targets' => [['kind' => 'SIZE', 'id' => $variant, 'amountMinor' => 1000]], 'leftOut' => []]], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();

    $refused = $browser->post(CAMPAIGNS_SCREEN."/{$campaign}", campaignsScreenFields(['sa'], [
        'version' => campaignsScreenVersion($campaign), 'name_en' => 'Ceiling Offers', 'intent' => 'schedule',
        'starts_at' => substr((string) DB::table('content.campaigns')->where('id', $campaign)->value('starts_at_local'), 0, 16),
        'ends_at' => substr((string) DB::table('content.campaigns')->where('id', $campaign)->value('ends_at_local'), 0, 16),
    ]), Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"));
    $errors = Screen::errors($refused);

    expect($errors)->toHaveKeys(['form', "rows.{$variant}", 'rows_store'])
        ->and($errors['rows_store'])->toBe(Fx::storeId('sa'))
        ->and(AdminBrowser::flashed($refused, 'status'))->toBe(__('content::admin_campaigns.saved_not_scheduled'))
        ->and(DB::table('content.campaigns')->where('id', $campaign)->value('name_en'))->toBe('Ceiling Offers')
        ->and(DB::table('content.campaign_runs')->where('id', $run)->value('state'))->toBe('DRAFT');
});

it('refuses every change of another store\'s campaign to staff of one store, for that alone, and changes nothing', function () {
    $owner = Screen::as(campaignsScreenEditor(['sa']));
    // Each in a state its own staff could act on, so only the store held can refuse (PR 8's second review): a
    // draft for its edits, a scheduled run to cancel, a live run to end.
    $draft = campaignsScreenCreate($owner, ['sa'], ['starts_at' => campaignsScreenTyped(10), 'ends_at' => campaignsScreenTyped(60 * 24)]);
    $scheduled = campaignsScreenCreate($owner, ['sa'], ['starts_at' => campaignsScreenTyped(10), 'ends_at' => campaignsScreenTyped(60 * 24)]);
    $live = campaignsScreenCreate($owner, ['sa'], ['starts_at' => campaignsScreenTyped(10), 'ends_at' => campaignsScreenTyped(60 * 24)]);

    foreach ([$scheduled, $live] as $campaign) {
        $owner->post(CAMPAIGNS_SCREEN."/{$campaign}/schedule", ['version' => campaignsScreenVersion($campaign)], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect()->assertSessionHasNoErrors();
    }

    DB::table('content.campaign_runs')->where('id', campaignsScreenRun($live))->update(['state' => 'LIVE', 'went_live_at' => CarbonImmutable::now()]);
    $before = DB::table('content.campaigns')->orderBy('id')->get()->all();
    $runsBefore = DB::table('content.campaign_runs')->orderBy('id')->get()->all();
    $other = Screen::as(campaignsScreenEditor(['ae']));
    $page = Screen::fromPage(CAMPAIGNS_SCREEN."/{$draft}");
    $run = campaignsScreenRun($draft);
    $version = campaignsScreenVersion($draft);

    $answers = [
        'prices' => $other->post(CAMPAIGNS_SCREEN."/runs/{$run}/prices", ['version' => $version, 'prices' => ['lines' => 'BOTH', 'targets' => [], 'leftOut' => []]], $page),
        'notice' => $other->post(CAMPAIGNS_SCREEN."/runs/{$run}/notice", ['version' => $version, 'notice' => ''], $page),
        'cancel' => $other->post(CAMPAIGNS_SCREEN."/{$scheduled}/cancel", ['version' => campaignsScreenVersion($scheduled), 'store' => Fx::storeId('sa')], $page),
        'end' => $other->post(CAMPAIGNS_SCREEN."/{$live}/end", ['version' => campaignsScreenVersion($live), 'store' => Fx::storeId('sa')], $page),
        'schedule' => $other->post(CAMPAIGNS_SCREEN."/{$draft}/schedule", ['version' => $version, 'store' => Fx::storeId('sa')], $page),
        'delete' => $other->post(CAMPAIGNS_SCREEN."/{$draft}/delete", ['version' => $version], $page),
        'theme-dates' => $other->post(CAMPAIGNS_SCREEN."/{$draft}/theme-dates", ['version' => $version], $page),
        'update' => $other->post(CAMPAIGNS_SCREEN."/{$draft}", campaignsScreenFields(['sa'], ['version' => $version, 'name_ar' => 'ليست لك']), $page),
    ];
    $said = array_map(static fn ($answer): ?string => Screen::errors($answer)['form'] ?? null, $answers);

    // Another store's campaign is no campaign of theirs (ContentAccess's item rule): the same words on every route
    // but Use Campaign's Dates, a Super Admin's (content.theme.manage), which is no job of theirs in any store.
    expect($said)->toBe([...array_fill_keys(array_keys($answers), FormErrors::message(new ContentNotFound)), 'theme-dates' => FormErrors::message(new Unauthorized(ContentPermissions::THEME_MANAGE))])
        ->and(DB::table('content.campaigns')->orderBy('id')->get()->all())->toEqual($before)
        ->and(DB::table('content.campaign_runs')->orderBy('id')->get()->all())->toEqual($runsBefore);
});

it('searches categories and brands as the other targets, 20 a page', function () {
    foreach (range(1, 21) as $n) {
        Px::category("Kitchens {$n}");
    }

    $browser = Screen::as(campaignsScreenEditor());
    $run = campaignsScreenRun(campaignsScreenCreate($browser));
    $first = $browser->get(CAMPAIGNS_SCREEN.'/targets?'.http_build_query(['run' => $run, 'kind' => 'CATEGORY', 'q' => 'Kitchens']), Screen::JSON)->assertOk();
    $more = $browser->get(CAMPAIGNS_SCREEN.'/targets?'.http_build_query(['run' => $run, 'kind' => 'CATEGORY', 'q' => 'Kitchens', 'after' => $first->json('next')]), Screen::JSON)->assertOk();

    expect($first->json('targets'))->toHaveCount(20)
        ->and($first->json('next'))->toBe('20')
        ->and(count($more->json('targets')))->toBeGreaterThanOrEqual(1)
        ->and($browser->get(CAMPAIGNS_SCREEN.'/targets?'.http_build_query(['run' => $run, 'kind' => 'CATEGORY', 'q' => 'Kitchens 21']), Screen::JSON)->json('targets'))->toHaveCount(1);
});

it('costs few queries of its own', function () {
    $browser = Screen::as(campaignsScreenEditor(['sa', 'ae']));
    $campaign = campaignsScreenCreate($browser, ['sa', 'ae']);

    // Measured 2026-11-11 (PR 8), on a reader who also holds Pricing's right: its frame is 19 - the panel's 10
    // (AdminFrameQueryBudgetTest), the bell's, and Pricing's Needs a Price count in the menu (8, Pricing's own). The list
    // 23, 4 of its own: the tabs' counts, the page, its runs' stores, and Pricing's count of each run's sizes - every row's
    // at once (W4's Offers; no theme or notice to name here). A campaign's page
    // of two stores 23, 4 of its own and 2 cache reads (the ceiling setting): the campaign and its runs, the themes on
    // offer, Translate's mark - and with Ops' own port one more a store for its draft notices (25, measured by a probe;
    // stood in here). Content's target is 15 of its own (§4.2). Pricing's preview is not among them: the page asks it as
    // the prices are typed, a store at a time.
    expect([Screen::queries($browser, CAMPAIGNS_SCREEN.'?store=sa'), Screen::queries($browser, CAMPAIGNS_SCREEN."/{$campaign}")])->toBe([23, 23]);
});

/**
 * Each run's campaign page addresses on W4b, by store code: locale => the full web address.
 *
 * @return array<string, array<string, string>>
 */
function campaignsScreenAddresses(AdminBrowser $browser, string $campaign): array
{
    $byStore = [];

    $browser->get(CAMPAIGNS_SCREEN."/{$campaign}")->assertOk()->assertInertia(function (AssertableInertia $page) use (&$byStore): void {
        foreach ($page->toArray()['props']['runs'] as $run) {
            $byStore[$run['store']['code']] = array_column($run['addresses'], 'url', 'locale');
        }
    });

    return $byStore;
}

it('shows the campaign page\'s full web address in each language once a store\'s run is scheduled, at no query of its own (owner, 2026-11-12)', function () {
    $browser = Screen::as(campaignsScreenEditor(['sa', 'ae']));
    $campaign = campaignsScreenCreate($browser, ['sa', 'ae']);

    expect(campaignsScreenAddresses($browser, $campaign))->toBe(['sa' => [], 'ae' => []]);

    $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/schedule", ['version' => campaignsScreenVersion($campaign), 'store' => Fx::storeId('sa')], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();
    $slugs = (array) DB::table('content.campaign_runs')->where('id', campaignsScreenRun($campaign))->first(['slug_ar', 'slug_en']);
    // On the site's root, APP_URL's (the canonical host, content.md §1.14), as the shop's own canonical tags: never a typed domain.
    $root = rtrim((string) config('app.url'), '/');

    expect($slugs['slug_en'])->not->toBe('')
        ->and(campaignsScreenAddresses($browser, $campaign))->toBe([
            'sa' => ['ar' => "{$root}/sa/ar/campaigns/".rawurlencode((string) $slugs['slug_ar']), 'en' => "{$root}/sa/en/campaigns/{$slugs['slug_en']}"],
            'ae' => [],
        ])
        // The addresses ride on the run's own row, read already: no query of their own. Measured 2026-11-12, the page with
        // one store's run scheduled is 25 - the draft's 23 (the test above) and Pricing's two reads of a scheduled run, its
        // saved prices and the sizes it skips (PR 8's).
        ->and(Screen::queries($browser, CAMPAIGNS_SCREEN."/{$campaign}"))->toBe(25);
});

it('shows no address for a cancelled run nor for an ended one: their pages answer 404 and 301 home', function () {
    $browser = Screen::as(campaignsScreenEditor(['sa', 'ae']));
    $campaign = campaignsScreenCreate($browser, ['sa', 'ae'], ['starts_at' => campaignsScreenTyped(10), 'ends_at' => campaignsScreenTyped(60 * 24)]);
    $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/schedule", ['version' => campaignsScreenVersion($campaign)], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();

    $both = campaignsScreenAddresses($browser, $campaign);
    expect($both['sa'])->toHaveKeys(['ar', 'en'])->and($both['ae'])->toHaveKeys(['ar', 'en']);

    $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/cancel", ['store' => Fx::storeId('ae')], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();
    // Live, then ended: as the run's own life goes (the job's going live stood in for by its row).
    DB::table('content.campaign_runs')->where('id', campaignsScreenRun($campaign, 'sa'))->update(['state' => 'LIVE', 'went_live_at' => CarbonImmutable::now()]);

    expect(campaignsScreenAddresses($browser, $campaign))->toBe(['sa' => $both['sa'], 'ae' => []]);

    $browser->post(CAMPAIGNS_SCREEN."/{$campaign}/end", ['store' => Fx::storeId('sa')], Screen::fromPage(CAMPAIGNS_SCREEN."/{$campaign}"))->assertRedirect();

    expect(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->pluck('state', 'store_id')->sort()->values()->all())->toBe(['CANCELLED', 'ENDED'])
        ->and(campaignsScreenAddresses($browser, $campaign))->toBe(['sa' => [], 'ae' => []]);
});
