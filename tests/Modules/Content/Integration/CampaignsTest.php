<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Enums\CatalogItem;
use Modules\Content\Application\Command\AdvanceCampaigns\AdvanceCampaigns;
use Modules\Content\Application\Command\AdvanceCampaigns\AdvanceCampaignsHandler;
use Modules\Content\Application\Command\CancelCampaign\CancelCampaign;
use Modules\Content\Application\Command\CancelCampaign\CancelCampaignHandler;
use Modules\Content\Application\Command\ChooseRunNotice\ChooseRunNotice;
use Modules\Content\Application\Command\ChooseRunNotice\ChooseRunNoticeHandler;
use Modules\Content\Application\Command\DeleteCampaign\DeleteCampaign;
use Modules\Content\Application\Command\DeleteCampaign\DeleteCampaignHandler;
use Modules\Content\Application\Command\EndCampaign\EndCampaign;
use Modules\Content\Application\Command\EndCampaign\EndCampaignHandler;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaign;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaignHandler;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBar;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBarHandler;
use Modules\Content\Application\Command\ScheduleCampaign\ScheduleCampaign;
use Modules\Content\Application\Command\ScheduleCampaign\ScheduleCampaignHandler;
use Modules\Content\Application\Command\SetRunPrices\SetRunPrices;
use Modules\Content\Application\Command\SetRunPrices\SetRunPricesHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\PreviewRunPrices\PreviewRunPricesHandler;
use Modules\Content\Application\RefusalWords;
use Modules\Content\Domain\Exception\CampaignNotChangeable;
use Modules\Content\Domain\Exception\CampaignPartlyScheduled;
use Modules\Content\Domain\Exception\CampaignPricesNotAllowed;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\NotDeletable;
use Modules\Content\Domain\Exception\NoticeNotDraft;
use Modules\Content\Domain\Exception\NoticeTaken;
use Modules\Content\Domain\Exception\StaleEdit;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Infrastructure\Eloquent\ContentCatalogUsage;
use Modules\Content\Public\Contracts\CampaignNotices;
use Modules\Content\Public\Contracts\ContentApi;
use Modules\Content\Public\Dto\NoticeChoiceDto;
use Modules\Content\Public\Events\CampaignExpiring;
use Modules\Content\Public\Events\CampaignWentLive;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Pricing\Public\Contracts\CampaignNames;
use Modules\Pricing\Public\Contracts\CampaignPrices;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Content\Support\StaleCampaigns;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| content.md §1.7: campaigns and their runs - one per store, each with its own id, its store's own moments (Q1 (a)),
| prices that stay in Content while a draft and reach Pricing only at Schedule (#14), one store at a time (#15), the
| half-live rule (#8), the notice (#10), going live once by the job's conditional update (#9), an off store's run
| waiting (Q17 (a)), the ending alerts by the table of #11, Cancel and End (#12) and `campaignForMessage` (#13).
|
| Events are read where the outbox keeps them: Ops' side of them is tests/Modules/Ops/Integration/CampaignEventsTest.
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    campaignsClock('2026-11-10 10:00:00+00');
    Queue::fake();
    campaignsNotices([]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function campaignsClock(string $utc): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($utc));
    Carbon::setTestNow(CarbonImmutable::now());
}

/** A UTC moment as staff type it in a store's zone. */
function campaignsTyped(string $utc, string $code = 'sa'): string
{
    return CarbonImmutable::parse($utc)->setTimezone(Cx::store($code)->timezone)->format('Y-m-d H:i');
}

/**
 * The campaign's job (and Pricing's, unless told) in these stores, acting as that staff member.
 *
 * @param  list<string>  $stores
 */
function campaignsEditor(array $stores = ['sa'], bool $pricing = true): string
{
    return Cx::editor([ContentPermissions::CAMPAIGN_MANAGE, ...($pricing ? [ContentPermissions::PRICING_PRICE_EDIT] : [])], $stores);
}

/**
 * A draft campaign through its use case, its dates typed as UTC moments read on Saudi Arabia's clock.
 *
 * @param  list<string>  $stores  codes
 */
function campaignsDraft(array $stores = ['sa'], string $starts = '2026-11-11 10:00:00+00', string $ends = '2026-11-16 10:00:00+00', ?string $nameEn = 'Ramadan Offers', ?string $themeId = null): string
{
    return app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض رمضان', $nameEn, campaignsTyped($starts), campaignsTyped($ends), array_map(Fx::storeId(...), $stores), $themeId));
}

function campaignsVersion(string $campaignId): int
{
    return (int) DB::table('content.campaigns')->where('id', $campaignId)->value('version');
}

function campaignsRun(string $campaignId, string $code = 'sa'): string
{
    return (string) DB::table('content.campaign_runs')->where('campaign_id', $campaignId)->where('store_id', Fx::storeId($code))->value('id');
}

function campaignsState(string $runId): string
{
    return (string) DB::table('content.campaign_runs')->where('id', $runId)->value('state');
}

/**
 * @param  array<string, mixed>  $with
 * @param  list<string>  $stores  codes
 */
function campaignsChange(string $campaignId, array $with = [], array $stores = ['sa']): string
{
    $row = DB::table('content.campaigns')->where('id', $campaignId)->first();

    return app(SaveCampaignHandler::class)->handle(new SaveCampaign(...[
        'campaignId' => $campaignId,
        'version' => campaignsVersion($campaignId),
        'nameAr' => (string) $row?->name_ar,
        'nameEn' => $row?->name_en,
        'startsAt' => substr((string) $row?->starts_at_local, 0, 16),
        'endsAt' => substr((string) $row?->ends_at_local, 0, 16),
        'storeIds' => array_map(Fx::storeId(...), $stores),
        'themeId' => $row?->theme_id,
        ...$with,
    ]));
}

/** @return list<string> the runs scheduled */
function campaignsSchedule(string $campaignId, ?string $code = null): array
{
    return app(ScheduleCampaignHandler::class)->handle(new ScheduleCampaign($campaignId, campaignsVersion($campaignId), $code === null ? null : Fx::storeId($code)));
}

/**
 * Schedule pressed by someone whose page read this version (the reads frozen by StaleCampaigns when a test says so).
 *
 * @return list<string>
 */
function campaignsScheduleRead(string $campaignId, int $version): array
{
    return app(ScheduleCampaignHandler::class)->handle(new ScheduleCampaign($campaignId, $version));
}

/** @param array<string, mixed> $prices */
function campaignsPrices(string $runId, array $prices): void
{
    $campaign = (string) DB::table('content.campaign_runs')->where('id', $runId)->value('campaign_id');
    app(SetRunPricesHandler::class)->handle(new SetRunPrices($runId, campaignsVersion($campaign), $prices));
}

/** @return array{lines: string, targets: list<array<string, mixed>>, leftOut: list<string>} */
function campaignsPercent(string $kind, string $id, int $basisPoints = 1000): array
{
    return ['lines' => 'BOTH', 'targets' => [['kind' => $kind, 'id' => $id, 'percentBp' => $basisPoints]], 'leftOut' => []];
}

function campaignsTick(): int
{
    return Fx::asSystem(fn (): int => app(AdvanceCampaignsHandler::class)->handle(new AdvanceCampaigns));
}

/**
 * The events of this kind the outbox holds, oldest first, as their payloads.
 *
 * @return list<array<string, mixed>>
 */
function campaignsEvents(string $type): array
{
    return array_values(array_map(static fn (object $row): array => (array) json_decode((string) $row->payload, true), DB::table('public.outbox_messages')->where('type', $type)->orderBy('created_at')->orderBy('event_id')->get()->all()));
}

/** How many ending alerts the outbox holds for a run. */
function campaignsAlerts(string $runId): int
{
    return count(array_filter(campaignsEvents(CampaignExpiring::class), static fn (array $event): bool => $event['campaignId'] === $runId));
}

/**
 * Ops' side of the port, stood in: these notices, by store code.
 *
 * @param  array<string, list<NoticeChoiceDto>>  $byStore
 */
function campaignsNotices(array $byStore): void
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

function campaignsRefusal(callable $work): string
{
    try {
        $work();
    } catch (CampaignNotChangeable $refused) {
        return "not_changeable:{$refused->rule}";
    } catch (InvalidContentInput $refused) {
        return "{$refused->attribute}:{$refused->rule}";
    } catch (MissingEnglish $missing) {
        return "{$missing->attribute}:english";
    } catch (DomainError $error) {
        return $error->type();
    }

    throw new LogicException('Not refused.');
}

describe('runs, stores and their clocks (§1.7 #1, #2, #5; Q1 (a))', function () {
    it('makes one run per store, each with its own id, 00:00 typed being each store\'s own midnight', function () {
        campaignsEditor(['sa', 'ae']);
        $campaign = app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض رمضان', 'Ramadan Offers', '2026-11-12 00:00', '2026-11-20 00:00', [Fx::storeId('ae'), Fx::storeId('sa')]));
        $sa = campaignsRun($campaign, 'sa');
        $ae = campaignsRun($campaign, 'ae');

        expect($sa)->not->toBe($ae)
            ->and(campaignsState($sa))->toBe('DRAFT')
            ->and(CarbonImmutable::parse((string) DB::table('content.campaign_runs')->where('id', $sa)->value('starts_at'))->utc()->format('Y-m-d H:i'))->toBe('2026-11-11 21:00')
            ->and(CarbonImmutable::parse((string) DB::table('content.campaign_runs')->where('id', $ae)->value('starts_at'))->utc()->format('Y-m-d H:i'))->toBe('2026-11-11 20:00')
            ->and(Fx::audits('content.campaign.created', $campaign))->toBe(1);
    });

    it('needs an end, an end after the start, Arabic to save, and the campaign\'s job in every store it names', function () {
        campaignsEditor(['sa']);

        expect(campaignsRefusal(fn () => app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض', null, '2026-11-12 00:00', null, [Fx::storeId('sa')]))))->toBe('ends_at:required')
            ->and(campaignsRefusal(fn () => app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض', null, '2026-11-12 00:00', '2026-11-11 00:00', [Fx::storeId('sa')]))))->toBe('ends_at:dates')
            ->and(campaignsRefusal(fn () => app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, null, 'Offers', '2026-11-12 00:00', '2026-11-13 00:00', [Fx::storeId('sa')]))))->toBe('name_ar:required')
            ->and(campaignsRefusal(fn () => app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض', null, '2026-11-12 00:00', '2026-11-13 00:00', []))))->toBe('stores:too_few')
            ->and(fn () => app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض', null, '2026-11-12 00:00', '2026-11-13 00:00', [Fx::storeId('sa'), Fx::storeId('ae')])))->toThrow(Unauthorized::class);
    });

    it('refuses a change of shared fields to whoever lacks the job in one of its stores, as if it were not there', function () {
        campaignsEditor(['sa', 'ae']);
        $campaign = campaignsDraft(['sa', 'ae']);
        campaignsEditor(['sa']);

        expect(fn () => campaignsChange($campaign, ['nameEn' => 'Other'], ['sa', 'ae']))->toThrow(ContentNotFound::class);
    });

    it('refuses a save over a newer version, keeping who saved it', function () {
        campaignsEditor();
        $campaign = campaignsDraft();
        campaignsChange($campaign, ['nameEn' => 'Ramadan']);

        expect(fn () => app(SaveCampaignHandler::class)->handle(new SaveCampaign($campaign, 1, 'عروض', 'Old', campaignsTyped('2026-11-11 10:00:00+00'), campaignsTyped('2026-11-16 10:00:00+00'), [Fx::storeId('sa')])))->toThrow(StaleEdit::class);
    });

    it('adds and removes a draft store, and refuses to drop a scheduled one or to revive anything over', function () {
        campaignsEditor(['sa', 'ae']);
        $campaign = campaignsDraft(['sa']);
        campaignsChange($campaign, [], ['sa', 'ae']);
        expect(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->count())->toBe(2);

        campaignsChange($campaign, [], ['sa']);
        expect(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->count())->toBe(1);

        campaignsSchedule($campaign);
        expect(campaignsRefusal(fn () => campaignsChange($campaign, [], ['ae'])))->toBe('not_changeable:store_scheduled');
    });
});

describe('prices: a draft\'s stay in Content, Schedule hands them to Pricing (§1.7 #4, #5, #14, #16)', function () {
    it('keeps a draft\'s choices in Content, writing nothing to Pricing, and needs Pricing\'s permission in the store', function () {
        $size = Px::ready(['60 cm']);
        Rx::set('sa', [$size['variants'][0] => '100']);
        campaignsEditor(['sa'], pricing: false);
        $campaign = campaignsDraft();
        $run = campaignsRun($campaign);

        expect(fn () => campaignsPrices($run, campaignsPercent('PRODUCT', $size['product'])))->toThrow(CampaignPricesNotAllowed::class);

        campaignsEditor(['sa']);
        campaignsPrices($run, campaignsPercent('PRODUCT', $size['product']));

        expect(DB::table('content.campaign_runs')->where('id', $run)->value('price_choices'))->toContain($size['product'])
            ->and(DB::table('pricing.campaign_sets')->count())->toBe(0)
            ->and(app(CampaignPrices::class)->read(StoreId::fromString(Fx::storeId('sa')), $run))->toBeNull()
            // The preview works from the choices without writing (§1.7 #6), the run not its own other offer.
            ->and(app(PreviewRunPricesHandler::class)->handle($run, campaignsPercent('PRODUCT', $size['product']))->products)->not->toBeEmpty()
            ->and(DB::table('pricing.campaign_sets')->count())->toBe(0);
    });

    it('refuses choices of the wrong shape before Pricing sees them', function () {
        campaignsEditor();
        $run = campaignsRun(campaignsDraft());

        expect(campaignsRefusal(fn () => campaignsPrices($run, ['lines' => 'ALL', 'targets' => []])))->toBe('lines:choice')
            ->and(campaignsRefusal(fn () => campaignsPrices($run, ['lines' => 'BOTH', 'targets' => [['kind' => 'PRODUCT', 'id' => 'x', 'percentBp' => 100]]])))->toBe('targets:format')
            ->and(campaignsRefusal(fn () => campaignsPrices($run, ['lines' => 'BOTH', 'targets' => [['kind' => 'CATEGORY', 'id' => (string) Str::ulid(), 'amountMinor' => 500]]])))->toBe('targets:range')
            ->and(campaignsRefusal(fn () => campaignsPrices($run, ['lines' => 'BOTH', 'targets' => [['kind' => 'PRODUCT', 'id' => (string) Str::ulid(), 'percentBp' => 10000]]])))->toBe('targets:range');
    });

    it('hands the choices to Pricing at Schedule and clears Content\'s copy; a run past its start keeps its prices', function () {
        $size = Px::ready(['60 cm']);
        Rx::set('sa', [$size['variants'][0] => '100']);
        campaignsEditor();
        $campaign = campaignsDraft();
        $run = campaignsRun($campaign);
        campaignsPrices($run, campaignsPercent('PRODUCT', $size['product']));

        expect(campaignsSchedule($campaign))->toBe([$run])
            ->and(campaignsState($run))->toBe('SCHEDULED')
            ->and(DB::table('content.campaign_runs')->where('id', $run)->value('price_choices'))->toBeNull()
            ->and(app(CampaignPrices::class)->read(StoreId::fromString(Fx::storeId('sa')), $run)?->targets)->toHaveCount(1)
            ->and(Fx::audits('content.campaign.scheduled', $run))->toBe(1);

        // Scheduled and not started: its prices change in Pricing at once.
        campaignsPrices($run, campaignsPercent('PRODUCT', $size['product'], 2000));
        expect(app(CampaignPrices::class)->read(StoreId::fromString(Fx::storeId('sa')), $run)?->targets[0]->percentBasisPoints)->toBe(2000);

        campaignsClock('2026-11-11 10:00:01+00');
        expect(campaignsRefusal(fn () => campaignsPrices($run, campaignsPercent('PRODUCT', $size['product']))))->toBe('not_changeable:prices_fixed');
    });

    it('schedules a campaign with no prices, and refuses one with choices to whoever lacks Pricing\'s permission there', function () {
        $size = Px::ready(['60 cm']);
        campaignsEditor(['sa', 'ae']);
        $campaign = campaignsDraft(['sa', 'ae']);
        campaignsPrices(campaignsRun($campaign, 'ae'), campaignsPercent('PRODUCT', $size['product']));
        // The campaign's job in both stores, Pricing's in neither.
        campaignsEditor(['sa', 'ae'], pricing: false);

        expect(fn () => campaignsSchedule($campaign))->toThrow(CampaignPricesNotAllowed::class)
            ->and(campaignsState(campaignsRun($campaign, 'sa')))->toBe('DRAFT');

        // One store alone, with no prices: "theme only" (§1.7 #16).
        expect(campaignsSchedule($campaign, 'sa'))->toHaveCount(1)
            ->and(campaignsState(campaignsRun($campaign, 'sa')))->toBe('SCHEDULED');
    });

    it('needs English to schedule, and refuses English blanked once a run is scheduled', function () {
        campaignsEditor();
        $campaign = campaignsDraft(nameEn: null);

        expect(campaignsRefusal(fn () => campaignsSchedule($campaign)))->toBe('name:english');

        campaignsChange($campaign, ['nameEn' => 'Ramadan']);
        campaignsSchedule($campaign);

        expect(campaignsRefusal(fn () => campaignsChange($campaign, ['nameEn' => null])))->toBe('name:english');
    });

    it('schedules one store at a time in store-id order: a store Pricing refuses stays a draft, the ones before stay scheduled', function () {
        $size = Px::ready(['60 cm']);
        campaignsEditor(['sa', 'ae', 'eg']);
        $campaign = campaignsDraft(['sa', 'ae', 'eg']);
        $order = DB::table('content.campaign_runs')->where('campaign_id', $campaign)->orderBy('store_id')->pluck('id')->all();
        // The second store's choices name a product that is not there: Pricing refuses its save.
        campaignsPrices($order[1], campaignsPercent('PRODUCT', strtolower((string) Str::ulid())));
        campaignsPrices($order[0], campaignsPercent('PRODUCT', $size['product']));

        $stopped = null;

        try {
            campaignsSchedule($campaign);
        } catch (CampaignPartlyScheduled $refusal) {
            $stopped = $refusal;
        }

        $name = static fn (int $n, string $locale): string => Cx::store((string) DB::table('platform.stores')->where('id', DB::table('content.campaign_runs')->where('id', $order[$n])->value('store_id'))->value('code'))->name->in($locale);

        // The refused store and the ones scheduled already are named, in both languages, the store's own refusal kept.
        $refusal = $stopped->refusal ?? new NotDeletable;

        expect($refusal->type())->toStartWith('pricing.')
            ->and($stopped?->getPrevious())->toBe($refusal)
            ->and($stopped?->context())->toMatchArray([
                'store' => $name(1, app()->getLocale()),
                'scheduled' => $name(0, app()->getLocale()),
                // The store's own reason, in its own words.
                'reason' => FormErrors::message($refusal),
                'refusal' => $refusal->type(),
            ])
            ->and(array_map(campaignsState(...), $order))->toBe(['SCHEDULED', 'DRAFT', 'DRAFT']);

        $was = app()->getLocale();

        foreach (['en' => 'Open the page again', 'ar' => 'افتح الصفحة من جديد'] as $locale => $reload) {
            app()->setLocale($locale);
            $reason = app(RefusalWords::class)->sentence($refusal);
            $words = FormErrors::message(new CampaignPartlyScheduled($name(1, $locale), $name(0, $locale), $reason, $refusal));

            expect($words)->toContain($name(1, $locale), $name(0, $locale), $reason, $reload)
                ->and(preg_match('/:[a-z]+/', $words))->toBe(0);
        }

        app()->setLocale($was);

        // Pressed again unmended: this press schedules nothing, and the store scheduled before is still named.
        $again = campaignsRefusal(fn () => campaignsSchedule($campaign));
        expect($again)->toBe('content.campaign_partly_scheduled');

        // Mended, another press schedules the rest.
        campaignsPrices($order[1], campaignsPercent('PRODUCT', $size['product']));
        expect(campaignsSchedule($campaign))->toBe([$order[1], $order[2]]);
    });

    it('names the stores that went through when a store fails with no refusal at all (retries spent), in words saying so', function () {
        campaignsEditor(['sa', 'ae']);
        $campaign = campaignsDraft(['sa', 'ae']);
        $order = DB::table('content.campaign_runs')->where('campaign_id', $campaign)->orderBy('store_id')->pluck('id')->all();
        DB::statement("CREATE FUNCTION content.campaigns_test_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.id = '{$order[1]}' AND NEW.state = 'SCHEDULED' THEN RAISE EXCEPTION 'away'; END IF; RETURN NEW; END \$\$");
        DB::statement('CREATE TRIGGER campaigns_test_fail BEFORE UPDATE ON content.campaign_runs FOR EACH ROW EXECUTE FUNCTION content.campaigns_test_fail()');
        $stopped = null;

        try {
            campaignsSchedule($campaign);
        } catch (CampaignPartlyScheduled $refusal) {
            $stopped = $refusal;
        }

        expect($stopped?->refusal)->toBeNull()
            ->and($stopped?->getPrevious())->toBeInstanceOf(QueryException::class)
            ->and($stopped?->category())->toBe(ErrorCategory::Unavailable)
            ->and($stopped?->reason)->toBe(__('content::errors.campaign_partly_scheduled.unexpected'))
            ->and($stopped?->context()['refusal'] ?? null)->toBe('unexpected')
            ->and(array_map(campaignsState(...), $order))->toBe(['SCHEDULED', 'DRAFT']);
    });
});

describe('going live (§1.7 #9; Q17 (a))', function () {
    it('goes live once by the conditional update, however often the job runs, the notice on its event', function () {
        campaignsNotices(['sa' => [new NoticeChoiceDto(strtolower((string) Str::ulid()), 'بدأت العروض', 'Offers started', 'DRAFT')]]);
        $notice = app(CampaignNotices::class)->drafts(StoreId::fromString(Fx::storeId('sa')), null, 10)[0]->id;
        campaignsEditor();
        $campaign = campaignsDraft();
        $run = campaignsRun($campaign);
        app(ChooseRunNoticeHandler::class)->handle(new ChooseRunNotice($run, campaignsVersion($campaign), $notice));
        campaignsSchedule($campaign);

        expect(campaignsTick())->toBe(0);

        campaignsClock('2026-11-11 10:00:00+00');
        campaignsTick();
        campaignsTick();
        // A second job that read the run before the first changed it: its update changes nothing, so fires nothing.
        expect(app(Campaigns::class)->goLive($run, CarbonImmutable::now()->toDateTimeImmutable()))->toBeFalse()
            ->and(campaignsState($run))->toBe('LIVE')
            ->and(campaignsEvents(CampaignWentLive::class))->toHaveCount(1)
            ->and(campaignsEvents(CampaignWentLive::class)[0])->toMatchArray(['campaignId' => $run, 'storeId' => Fx::storeId('sa'), 'noticeId' => $notice]);
    });

    it('carries on past a run that fails, then fails the job so its retry carries on', function () {
        campaignsEditor(['sa', 'ae']);
        $campaign = campaignsDraft(['sa', 'ae']);
        campaignsSchedule($campaign);
        campaignsClock('2026-11-11 11:00:00+00');
        // A run whose row breaks its transaction: its write is refused by a CHECK the code never meets otherwise.
        DB::statement("CREATE FUNCTION content.campaigns_test_refuse() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.store_id = '".Fx::storeId('sa')."' AND NEW.state = 'LIVE' THEN RAISE EXCEPTION 'refused'; END IF; RETURN NEW; END \$\$");
        DB::statement('CREATE TRIGGER campaigns_test_refuse BEFORE UPDATE ON content.campaign_runs FOR EACH ROW EXECUTE FUNCTION content.campaigns_test_refuse()');

        expect(fn () => campaignsTick())->toThrow(QueryException::class)
            ->and(campaignsState(campaignsRun($campaign, 'ae')))->toBe('LIVE')
            ->and(campaignsState(campaignsRun($campaign, 'sa')))->toBe('SCHEDULED');

        DB::statement('DROP TRIGGER campaigns_test_refuse ON content.campaign_runs');
        campaignsTick();

        expect(campaignsState(campaignsRun($campaign, 'sa')))->toBe('LIVE')
            ->and(campaignsEvents(CampaignWentLive::class))->toHaveCount(2);
    });

    it('lets a run of an off store wait, with no notice and no alert, and go live once the store is back before its end', function () {
        campaignsEditor(['eg']);
        $campaign = campaignsDraft(['eg']);
        $run = campaignsRun($campaign, 'eg');
        campaignsSchedule($campaign);
        $eg = Cx::switchOff('eg');
        // Typed on Saudi Arabia's clock, read on Egypt's: it starts at 11:00 UTC there.
        campaignsClock('2026-11-11 12:00:00+00');
        campaignsTick();

        expect(campaignsState($run))->toBe('SCHEDULED')
            ->and(campaignsEvents(CampaignWentLive::class))->toBe([]);

        DB::table('platform.stores')->where('id', $eg)->update(['is_active' => true]);
        app(StoreDirectory::class)->invalidate();
        campaignsTick();

        expect(campaignsState($run))->toBe('LIVE')
            ->and(campaignsEvents(CampaignWentLive::class))->toHaveCount(1);
    });

    it('ends a run whose end came without its going live with no event, and a live one on time', function () {
        campaignsEditor(['sa', 'ae']);
        $campaign = campaignsDraft(['sa', 'ae']);
        campaignsSchedule($campaign);
        // Its id first: an off store is no store by its code (lesson 187).
        $ae = campaignsRun($campaign, 'ae');
        Cx::switchOff('ae');
        campaignsClock('2026-11-12 10:00:00+00');
        campaignsTick();
        campaignsClock('2026-11-16 10:00:00+00');
        campaignsTick();

        expect(campaignsState(campaignsRun($campaign, 'sa')))->toBe('ENDED')
            ->and(campaignsState($ae))->toBe('ENDED')
            ->and(DB::table('content.campaign_runs')->where('id', $ae)->value('went_live_at'))->toBeNull()
            ->and(campaignsEvents(CampaignWentLive::class))->toHaveCount(1);
    });
});

describe('Cancel and End (§1.7 #9, #10, #12)', function () {
    it('cancels a scheduled run: its prices go, its notice\'s tie is cleared, no event; whichever comes first of Cancel and go-live wins', function () {
        $size = Px::ready(['60 cm']);
        $notice = strtolower((string) Str::ulid());
        campaignsNotices(['sa' => [new NoticeChoiceDto($notice, 'بدأت', 'Started', 'DRAFT')], 'ae' => []]);
        campaignsEditor(['sa', 'ae']);
        $campaign = campaignsDraft(['sa', 'ae'], starts: '2026-11-10 11:00:00+00');
        $sa = campaignsRun($campaign, 'sa');
        campaignsPrices($sa, campaignsPercent('PRODUCT', $size['product']));
        app(ChooseRunNoticeHandler::class)->handle(new ChooseRunNotice($sa, campaignsVersion($campaign), $notice));
        campaignsSchedule($campaign);

        expect(app(CancelCampaignHandler::class)->handle(new CancelCampaign($campaign, Fx::storeId('sa'))))->toBe([$sa])
            ->and(campaignsState($sa))->toBe('CANCELLED')
            ->and(DB::table('content.campaign_runs')->where('id', $sa)->value('notice_id'))->toBeNull()
            ->and(DB::table('pricing.campaign_sets')->where('run_id', $sa)->count())->toBe(0);

        // The other store went live first: Cancel loses and is told.
        campaignsClock('2026-11-10 11:00:00+00');
        campaignsTick();

        expect(campaignsRefusal(fn () => app(CancelCampaignHandler::class)->handle(new CancelCampaign($campaign))))->toBe('not_changeable:went_live')
            ->and(campaignsEvents(CampaignWentLive::class))->toHaveCount(1)
            ->and(campaignsEvents(CampaignWentLive::class)[0]['campaignId'])->toBe(campaignsRun($campaign, 'ae'));
    });

    it('ends a live run now, its prices stopping now; End refuses a run not live; a cancelled run never goes live', function () {
        $size = Px::ready(['60 cm']);
        Rx::set('sa', [$size['variants'][0] => '100']);
        campaignsEditor();
        $campaign = campaignsDraft(starts: '2026-11-10 11:00:00+00');
        $run = campaignsRun($campaign);
        campaignsPrices($run, campaignsPercent('PRODUCT', $size['product']));
        campaignsSchedule($campaign);

        expect(campaignsRefusal(fn () => app(EndCampaignHandler::class)->handle(new EndCampaign($campaign))))->toBe('not_changeable:not_live');

        campaignsClock('2026-11-10 12:00:00+00');
        campaignsTick();
        app(EndCampaignHandler::class)->handle(new EndCampaign($campaign));

        expect(campaignsState($run))->toBe('ENDED')
            ->and(CarbonImmutable::parse((string) DB::table('pricing.campaign_sets')->where('run_id', $run)->value('ends_at'))->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-10 12:00:00')
            ->and(campaignsRefusal(fn () => campaignsChange($campaign, ['endsAt' => campaignsTyped('2026-11-20 10:00:00+00')])))->toBe('not_changeable:over');
    });

    it('deletes a campaign only while every run is a draft', function () {
        campaignsEditor();
        $draft = campaignsDraft();
        $scheduled = campaignsDraft();
        campaignsSchedule($scheduled);

        expect(fn () => app(DeleteCampaignHandler::class)->handle(new DeleteCampaign($scheduled, campaignsVersion($scheduled))))->toThrow(NotDeletable::class);

        app(DeleteCampaignHandler::class)->handle(new DeleteCampaign($draft, campaignsVersion($draft)));
        expect(DB::table('content.campaigns')->where('id', $draft)->count())->toBe(0)
            ->and(Fx::audits('content.campaign.deleted', $draft))->toBe(1);
    });
});

describe('half live (§1.7 #8)', function () {
    it('locks the start, the stores and the theme once any run is live; the name and the end still change, reaching scheduled and live runs, never an ended one', function () {
        $size = Px::ready(['60 cm']);
        Rx::set('sa', [$size['variants'][0] => '100']);
        campaignsEditor(['sa', 'ae', 'eg']);
        // 00:00 typed: the UAE's midnight comes an hour before Saudi Arabia's.
        $campaign = app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض', 'Offers', '2026-11-12 00:00', '2026-11-20 00:00', [Fx::storeId('sa'), Fx::storeId('ae')]));
        campaignsPrices(campaignsRun($campaign, 'sa'), campaignsPercent('PRODUCT', $size['product']));
        campaignsSchedule($campaign);
        campaignsClock('2026-11-11 20:30:00+00');
        campaignsTick();

        expect(campaignsState(campaignsRun($campaign, 'ae')))->toBe('LIVE')
            ->and(campaignsState(campaignsRun($campaign, 'sa')))->toBe('SCHEDULED');

        $change = static fn (array $with, array $stores = ['sa', 'ae']) => campaignsRefusal(fn () => app(SaveCampaignHandler::class)->handle(new SaveCampaign(...[
            'campaignId' => $campaign, 'version' => campaignsVersion($campaign), 'nameAr' => 'عروض', 'nameEn' => 'Offers', 'startsAt' => '2026-11-12 00:00', 'endsAt' => '2026-11-20 00:00', 'storeIds' => array_map(Fx::storeId(...), $stores), ...$with,
        ])));

        expect($change(['startsAt' => '2026-11-12 01:00']))->toBe('not_changeable:locked')
            ->and($change([], ['sa', 'ae', 'eg']))->toBe('not_changeable:locked')
            ->and($change([], ['ae']))->toBe('not_changeable:locked');

        app(SaveCampaignHandler::class)->handle(new SaveCampaign($campaign, campaignsVersion($campaign), 'عروض', 'Better Offers', '2026-11-12 00:00', '2026-11-22 00:00', [Fx::storeId('sa'), Fx::storeId('ae')]));

        expect(DB::table('content.campaigns')->where('id', $campaign)->value('name_en'))->toBe('Better Offers')
            ->and(CarbonImmutable::parse((string) DB::table('content.campaign_runs')->where('id', campaignsRun($campaign, 'sa'))->value('ends_at'))->utc()->format('Y-m-d H:i'))->toBe('2026-11-21 21:00')
            ->and(CarbonImmutable::parse((string) DB::table('content.campaign_runs')->where('id', campaignsRun($campaign, 'ae'))->value('ends_at'))->utc()->format('Y-m-d H:i'))->toBe('2026-11-21 20:00')
            ->and(CarbonImmutable::parse((string) DB::table('pricing.campaign_sets')->where('run_id', campaignsRun($campaign, 'sa'))->value('ends_at'))->utc()->format('Y-m-d H:i'))->toBe('2026-11-21 21:00');

        // An ended run stays ended whatever the end becomes.
        app(EndCampaignHandler::class)->handle(new EndCampaign($campaign, Fx::storeId('ae')));
        app(SaveCampaignHandler::class)->handle(new SaveCampaign($campaign, campaignsVersion($campaign), 'عروض', 'Better Offers', '2026-11-12 00:00', '2026-11-25 00:00', [Fx::storeId('sa'), Fx::storeId('ae')]));

        expect(campaignsState(campaignsRun($campaign, 'ae')))->toBe('ENDED');
    });
});

describe('the notice (§1.7 #10)', function () {
    it('takes one of the store\'s drafts, one notice for one run at a time, never after go-live', function () {
        $draft = strtolower((string) Str::ulid());
        $sent = strtolower((string) Str::ulid());
        $other = strtolower((string) Str::ulid());
        campaignsNotices(['sa' => [new NoticeChoiceDto($draft, 'أ', 'A', 'DRAFT'), new NoticeChoiceDto($sent, 'ب', 'B', 'SENT')], 'ae' => [new NoticeChoiceDto($other, 'ج', 'C', 'DRAFT')]]);
        campaignsEditor(['sa', 'ae']);
        $first = campaignsDraft();
        $second = campaignsDraft();
        $choose = static fn (string $campaign, string $notice, string $code = 'sa') => app(ChooseRunNoticeHandler::class)->handle(new ChooseRunNotice(campaignsRun($campaign, $code), campaignsVersion($campaign), $notice));

        expect(fn () => $choose($first, $sent))->toThrow(NoticeNotDraft::class)
            ->and(fn () => $choose($first, $other))->toThrow(NoticeNotDraft::class);

        $choose($first, $draft);
        expect(fn () => $choose($second, $draft))->toThrow(NoticeTaken::class);

        // The index behind the check: a second run's write over the check is refused the same way.
        expect(fn () => DB::transaction(fn () => DB::table('content.campaign_runs')->where('id', campaignsRun($second))->update(['notice_id' => $draft])))->toThrow(QueryException::class);

        // And the code refuses before the index does (lesson 36): with the index gone, still refused.
        DB::transaction(function () use ($choose, $second, $draft): void {
            DB::statement('DROP INDEX content.campaign_runs_notice');
            expect(fn () => $choose($second, $draft))->toThrow(NoticeTaken::class);
            DB::statement("CREATE UNIQUE INDEX campaign_runs_notice ON content.campaign_runs (notice_id) WHERE notice_id IS NOT NULL AND state IN ('DRAFT', 'SCHEDULED')");
        });

        campaignsSchedule($first);
        campaignsClock('2026-11-11 10:00:00+00');
        campaignsTick();

        expect(campaignsRefusal(fn () => $choose($first, $draft)))->toBe('not_changeable:notice_sent');
    });
});

/** A scheduled run in Saudi Arabia from the start given, of the length given in hours, and its id. */
function campaignsLive(int $hours, string $starts = '2026-11-11 10:00:00+00', string $code = 'sa'): string
{
    campaignsEditor([$code]);
    $ends = CarbonImmutable::parse($starts)->addHours($hours)->toIso8601String();
    // Typed on the store's own clock, so its moments are the ones given.
    $campaign = app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض رمضان', 'Ramadan Offers', campaignsTyped($starts, $code), campaignsTyped($ends, $code), [Fx::storeId($code)]));
    campaignsSchedule($campaign);

    return campaignsRun($campaign, $code);
}

/** The clock so many hours after a start, and one tick of the job. */
function campaignsAt(string $starts, int $hoursAfter): void
{
    campaignsClock(CarbonImmutable::parse($starts)->addHours($hoursAfter)->toIso8601String());
    campaignsTick();
}

describe('the ending alerts, one test per row of §1.7 #11', function () {
    it('sends 72 hours before the end and 24 hours before it for a run of 3 days or more', function () {
        $run = campaignsLive(120);
        campaignsAt('2026-11-11 10:00:00+00', 0);
        expect(campaignsAlerts($run))->toBe(0);
        campaignsAt('2026-11-11 10:00:00+00', 47);
        expect(campaignsAlerts($run))->toBe(0);
        campaignsAt('2026-11-11 10:00:00+00', 48);
        // A second job that judged the alert due before the first stamped it: its stamp changes nothing, so it fires
        // nothing (§1.7 #11, "goes once").
        expect(campaignsAlerts($run))->toBe(1)
            ->and(app(Campaigns::class)->stampWarning($run, 72, CarbonImmutable::now()->toDateTimeImmutable()))->toBeFalse();
        campaignsAt('2026-11-11 10:00:00+00', 95);
        expect(campaignsAlerts($run))->toBe(1);
        campaignsAt('2026-11-11 10:00:00+00', 96);
        campaignsAt('2026-11-11 10:00:00+00', 100);
        expect(campaignsAlerts($run))->toBe(2);
    });

    it('sends the 72-hour alert as it goes live for exactly 3 days', function () {
        $run = campaignsLive(72);
        campaignsAt('2026-11-11 10:00:00+00', 0);
        expect(campaignsAlerts($run))->toBe(1);
        campaignsAt('2026-11-11 10:00:00+00', 48);
        expect(campaignsAlerts($run))->toBe(2);
    });

    it('sends only the 24-hour alert from 24 hours up to under 3 days, and exactly 24 hours as it goes live', function () {
        $two = campaignsLive(71);
        campaignsAt('2026-11-11 10:00:00+00', 0);
        campaignsAt('2026-11-11 10:00:00+00', 46);
        expect(campaignsAlerts($two))->toBe(0);
        campaignsAt('2026-11-11 10:00:00+00', 47);
        expect(campaignsAlerts($two))->toBe(1);

        $day = campaignsLive(24, '2026-11-14 10:00:00+00');
        campaignsAt('2026-11-14 10:00:00+00', 0);
        expect(campaignsAlerts($day))->toBe(1);
    });

    it('sends none under 24 hours', function () {
        $run = campaignsLive(23);
        foreach ([0, 1, 12, 22] as $hours) {
            campaignsAt('2026-11-11 10:00:00+00', $hours);
        }

        expect(campaignsAlerts($run))->toBe(0);
    });

    it('going live late sends the 72-hour alert at once only while more than 24 hours are left, the 24-hour one at once within them', function () {
        $late = campaignsLive(120);
        // The job was down until 48 hours before the end.
        campaignsAt('2026-11-11 10:00:00+00', 72);
        expect(campaignsState($late))->toBe('LIVE')->and(campaignsAlerts($late))->toBe(1);

        $later = campaignsLive(120, '2026-11-20 10:00:00+00');
        campaignsAt('2026-11-20 10:00:00+00', 108);
        // 12 hours left: the 24-hour alert at once, never the 72-hour one.
        expect(campaignsAlerts($later))->toBe(1)
            ->and(DB::table('content.campaign_runs')->where('id', $later)->value('warned_72_at'))->toBeNull()
            ->and(DB::table('content.campaign_runs')->where('id', $later)->value('warned_24_at'))->not->toBeNull();
    });

    it('holds the alerts while the store is off, then decides them as late when it is back; a live run stays live', function () {
        // Not the base store, which is never off.
        $run = campaignsLive(120, code: 'ae');
        campaignsAt('2026-11-11 10:00:00+00', 0);
        $ae = Cx::switchOff('ae');
        campaignsAt('2026-11-11 10:00:00+00', 50);
        campaignsAt('2026-11-11 10:00:00+00', 100);

        expect(campaignsState($run))->toBe('LIVE')->and(campaignsAlerts($run))->toBe(0);

        DB::table('platform.stores')->where('id', $ae)->update(['is_active' => true]);
        app(StoreDirectory::class)->invalidate();
        campaignsAt('2026-11-11 10:00:00+00', 100);

        // 20 hours left: the 72-hour alert is gone by, the 24-hour one goes at once.
        expect(campaignsAlerts($run))->toBe(1)
            ->and(DB::table('content.campaign_runs')->where('id', $run)->value('warned_24_at'))->not->toBeNull();
    });

    it('clears a sent alert\'s stamp when the end moves its moment ahead again, and sends it again when it comes', function () {
        $run = campaignsLive(120);
        $campaign = (string) DB::table('content.campaign_runs')->where('id', $run)->value('campaign_id');
        campaignsAt('2026-11-11 10:00:00+00', 48);
        expect(campaignsAlerts($run))->toBe(1);

        campaignsChange($campaign, ['endsAt' => campaignsTyped('2026-11-21 10:00:00+00')]);
        expect(DB::table('content.campaign_runs')->where('id', $run)->value('warned_72_at'))->toBeNull();

        campaignsAt('2026-11-11 10:00:00+00', 168);
        expect(campaignsAlerts($run))->toBe(2);
    });

    it('does not send a passed and sent alert again when the end moves; sends a passed and unsent one at once', function () {
        $run = campaignsLive(120);
        $campaign = (string) DB::table('content.campaign_runs')->where('id', $run)->value('campaign_id');
        campaignsAt('2026-11-11 10:00:00+00', 50);
        expect(campaignsAlerts($run))->toBe(1);

        // An hour earlier: 69 hours left, the moment passed, sent already.
        campaignsChange($campaign, ['endsAt' => campaignsTyped('2026-11-16 09:00:00+00')]);
        campaignsTick();
        expect(campaignsAlerts($run))->toBe(1);

        $unsent = campaignsLive(200, '2026-11-20 10:00:00+00');
        $other = (string) DB::table('content.campaign_runs')->where('id', $unsent)->value('campaign_id');
        campaignsAt('2026-11-20 10:00:00+00', 10);
        expect(campaignsAlerts($unsent))->toBe(0);

        // The end brought in to 66 hours from now, 76 hours long: the 72-hour moment passed unsent, more than 24 left.
        campaignsChange($other, ['endsAt' => campaignsTyped('2026-11-23 14:00:00+00')]);
        campaignsTick();
        expect(campaignsAlerts($unsent))->toBe(1);
    });

    it('sends none from the moment the end moves so the length no longer qualifies; never for an ended or cancelled run', function () {
        $run = campaignsLive(120);
        $campaign = (string) DB::table('content.campaign_runs')->where('id', $run)->value('campaign_id');
        campaignsAt('2026-11-11 10:00:00+00', 1);
        // 20 hours long now: no alert at all.
        campaignsChange($campaign, ['endsAt' => campaignsTyped('2026-11-12 06:00:00+00')]);
        campaignsAt('2026-11-11 10:00:00+00', 10);
        expect(campaignsAlerts($run))->toBe(0);

        $cancelled = campaignsLive(120, '2026-11-20 10:00:00+00');
        $ended = campaignsLive(120, '2026-11-20 10:00:00+00');
        app(CancelCampaignHandler::class)->handle(new CancelCampaign((string) DB::table('content.campaign_runs')->where('id', $cancelled)->value('campaign_id')));
        campaignsAt('2026-11-20 10:00:00+00', 0);
        app(EndCampaignHandler::class)->handle(new EndCampaign((string) DB::table('content.campaign_runs')->where('id', $ended)->value('campaign_id')));

        foreach ([48, 100, 119] as $hours) {
            campaignsAt('2026-11-20 10:00:00+00', $hours);
        }

        expect(campaignsAlerts($cancelled))->toBe(0)
            ->and(campaignsAlerts($ended))->toBe(0);
    });
});

describe('what other modules read (§1.7 #13; §2.1; the CampaignNames port)', function () {
    it('answers campaignForMessage for a live run only, with the run\'s store, null for an unknown id', function () {
        campaignsEditor(['sa', 'ae']);
        $campaign = campaignsDraft(['sa', 'ae']);
        $sa = campaignsRun($campaign, 'sa');
        $content = app(ContentApi::class);

        expect($content->campaignForMessage($sa))->toBeNull()
            ->and($content->campaignForMessage(strtolower((string) Str::ulid())))->toBeNull()
            ->and($content->campaignForMessage('not-an-id'))->toBeNull();

        campaignsSchedule($campaign);
        expect($content->campaignForMessage($sa))->toBeNull();

        campaignsClock('2026-11-11 10:00:00+00');
        campaignsTick();
        $read = $content->campaignForMessage(strtoupper($sa));

        expect($read?->campaignId)->toBe($sa)
            ->and($read?->storeId)->toBe(Fx::storeId('sa'))
            ->and($read?->name->en)->toBe('Ramadan Offers')
            ->and($content->campaignForMessage(campaignsRun($campaign, 'ae'))?->storeId)->toBe(Fx::storeId('ae'));

        app(EndCampaignHandler::class)->handle(new EndCampaign($campaign, Fx::storeId('sa')));
        expect($content->campaignForMessage($sa))->toBeNull()
            // Pricing's notes and Sync's report keep naming an ended run, with its page.
            ->and(app(CampaignNames::class)->named([$sa])[0]->path)->toBe("/admin/content/campaigns/runs/{$sa}");
    });

    it('holds the categories and brands a run names, so Catalog\'s delete is refused, and lets them go when it is cancelled', function () {
        $category = Px::category('Kitchens');
        $brand = Px::brand('Hafele');
        campaignsEditor();
        $campaign = campaignsDraft();
        $run = campaignsRun($campaign);
        campaignsPrices($run, ['lines' => 'BOTH', 'targets' => [['kind' => 'CATEGORY', 'id' => $category, 'percentBp' => 500], ['kind' => 'BRAND', 'id' => $brand, 'percentBp' => 500]], 'leftOut' => []]);

        expect(app(ContentCatalogUsage::class)->usesOf(CatalogItem::Category, [$category]))->toHaveCount(1)
            ->and(app(ContentCatalogUsage::class)->usesOf(CatalogItem::Brand, [$brand]))->toHaveCount(1)
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.brands')->where('id', $brand)->delete()))->toThrow(QueryException::class);

        campaignsSchedule($campaign);
        expect(app(ContentCatalogUsage::class)->usesOf(CatalogItem::Category, [$category]))->toHaveCount(1);

        app(CancelCampaignHandler::class)->handle(new CancelCampaign($campaign));
        expect(app(ContentCatalogUsage::class)->usesOf(CatalogItem::Category, [$category]))->toBe([]);
    });

    it('takes a smart bar for one of the store\'s runs, which goes with its run', function () {
        Cx::editor([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::BAR_MANAGE], ['sa', 'ae']);
        $campaign = campaignsDraft(['sa', 'ae']);
        $save = static fn (string $run) => app(SaveSmartBarHandler::class)->handle(new SaveSmartBar(null, Fx::storeId('sa'), 'CAMPAIGN', null, $run, 'خصم', 'Sale', null));

        expect(campaignsRefusal(fn () => $save(campaignsRun($campaign, 'ae'))))->toBe('run:run');

        $bar = $save(campaignsRun($campaign, 'sa'));
        app(DeleteCampaignHandler::class)->handle(new DeleteCampaign($campaign, campaignsVersion($campaign)));

        expect(DB::table('content.smart_bars')->where('id', $bar)->count())->toBe(0);
    });
});

describe('a copy read before another change committed (review of #382)', function () {
    it('sends the notice chosen since the job read the run, not the one it read', function () {
        $old = strtolower((string) Str::ulid());
        $new = strtolower((string) Str::ulid());
        campaignsNotices(['sa' => [new NoticeChoiceDto($old, 'أ', 'Old', 'DRAFT'), new NoticeChoiceDto($new, 'ب', 'New', 'DRAFT')]]);
        campaignsEditor();
        $campaign = campaignsDraft();
        $run = campaignsRun($campaign);
        app(ChooseRunNoticeHandler::class)->handle(new ChooseRunNotice($run, campaignsVersion($campaign), $old));
        campaignsSchedule($campaign);
        campaignsClock('2026-11-11 10:00:00+00');

        StaleCampaigns::freeze(CarbonImmutable::now()->toDateTimeImmutable(), [Fx::storeId('sa'), Fx::storeId('ae'), Fx::storeId('eg')]);
        app(ChooseRunNoticeHandler::class)->handle(new ChooseRunNotice($run, campaignsVersion($campaign), $new));
        campaignsTick();

        expect(campaignsEvents(CampaignWentLive::class))->toHaveCount(1)
            ->and(campaignsEvents(CampaignWentLive::class)[0]['noticeId'])->toBe($new);
    });

    it('judges an alert on the end moved since the job read the run, and keeps its stamp for the new moment', function () {
        $run = campaignsLive(120);
        $campaign = (string) DB::table('content.campaign_runs')->where('id', $run)->value('campaign_id');
        campaignsAt('2026-11-11 10:00:00+00', 0);
        campaignsClock('2026-11-13 10:00:00+00');

        // Read when the 72-hour alert was due; the end then moved five days on.
        StaleCampaigns::freeze(CarbonImmutable::now()->toDateTimeImmutable(), [Fx::storeId('sa')]);
        campaignsChange($campaign, ['endsAt' => campaignsTyped('2026-11-21 10:00:00+00')]);
        campaignsTick();

        expect(campaignsAlerts($run))->toBe(0)
            ->and(DB::table('content.campaign_runs')->where('id', $run)->value('warned_72_at'))->toBeNull();
    });

    it('tells Cancel it lost when the run went live after Cancel read it, once its row is locked', function () {
        campaignsEditor();
        $campaign = campaignsDraft();
        $run = campaignsRun($campaign);
        campaignsSchedule($campaign);
        StaleCampaigns::freeze(CarbonImmutable::now()->toDateTimeImmutable(), [], $campaign);
        campaignsClock('2026-11-11 10:00:00+00');
        Fx::asSystem(fn () => app(Campaigns::class)->goLive($run, CarbonImmutable::now()->toDateTimeImmutable()));

        expect(campaignsRefusal(fn () => app(CancelCampaignHandler::class)->handle(new CancelCampaign($campaign))))->toBe('not_changeable:went_live')
            ->and(campaignsState($run))->toBe('LIVE');
    });

    it('asks Pricing\'s permission again for the choices a run holds when it is scheduled, not those read before', function () {
        $size = Px::ready(['60 cm']);
        $campaign = (static function (): string {
            campaignsEditor();

            return campaignsDraft();
        })();
        $run = campaignsRun($campaign);
        // The scheduler, without Pricing's permission, read the run with no choices.
        Fx::actAsStaff(Fx::staffWith([ContentPermissions::CAMPAIGN_MANAGE], ['sa']));
        $version = campaignsVersion($campaign);
        StaleCampaigns::freeze(CarbonImmutable::now()->toDateTimeImmutable(), [], $campaign);
        // The run then held choices: written straight, so only the price right can catch it, not the version.
        DB::table('content.campaign_runs')->where('id', $run)->update(['price_choices' => json_encode(campaignsPercent('PRODUCT', $size['product']))]);

        expect(fn () => campaignsScheduleRead($campaign, $version))->toThrow(CampaignPricesNotAllowed::class)
            ->and(campaignsState($run))->toBe('DRAFT')
            ->and(DB::table('pricing.campaign_sets')->count())->toBe(0);
    });

    it('refuses a schedule read before the campaign changed, on its locked row: the version', function () {
        campaignsEditor();
        $campaign = campaignsDraft();
        $version = campaignsVersion($campaign);
        StaleCampaigns::freeze(CarbonImmutable::now()->toDateTimeImmutable(), [], $campaign);
        campaignsChange($campaign, ['nameEn' => 'Changed meanwhile']);

        expect(fn () => campaignsScheduleRead($campaign, $version))->toThrow(StaleEdit::class)
            ->and(campaignsState(campaignsRun($campaign)))->toBe('DRAFT');
    });

    it('refuses on the locked rows an English name blanked, an end passed and a notice no longer a draft since the read', function (string $change) {
        $notice = strtolower((string) Str::ulid());
        // Ops' answer about the notice: once the change is armed, a draft when Schedule first asks, sent by the time
        // its locked transaction asks again.
        $asked = new ArrayObject(['armed' => 0, 'calls' => 0]);
        app()->instance(CampaignNotices::class, new class($notice, $asked) implements CampaignNotices
        {
            /** @param ArrayObject<string, int> $asked */
            public function __construct(private string $notice, private ArrayObject $asked) {}

            public function drafts(StoreId $store, ?string $after, int $limit): array
            {
                return [];
            }

            public function named(StoreId $store, array $ids): array
            {
                $sent = $this->asked['armed'] === 1 && ++$this->asked['calls'] > 1;

                return [new NoticeChoiceDto($this->notice, 'أ', 'A', $sent ? 'SENT' : 'DRAFT')];
            }
        });
        campaignsEditor();
        $campaign = campaignsDraft();
        $run = campaignsRun($campaign);
        app(ChooseRunNoticeHandler::class)->handle(new ChooseRunNotice($run, campaignsVersion($campaign), $notice));
        $version = campaignsVersion($campaign);
        StaleCampaigns::freeze(CarbonImmutable::now()->toDateTimeImmutable(), [], $campaign);

        // Each change written straight, so the version does not catch it: the locked rows' own check must.
        match ($change) {
            'english' => DB::table('content.campaigns')->where('id', $campaign)->update(['name_en' => null]),
            'end' => DB::table('content.campaign_runs')->where('id', $run)->update(['starts_at' => '2026-11-01 00:00:00+00', 'ends_at' => '2026-11-02 00:00:00+00']),
            default => $asked['armed'] = 1,
        };

        expect(campaignsRefusal(fn () => campaignsScheduleRead($campaign, $version)))->toBe(match ($change) {
            'english' => 'name:english',
            'end' => 'ends_at:past',
            default => 'content.notice_not_draft',
        })->and(campaignsState($run))->toBe('DRAFT');
    })->with(['the English name' => ['english'], 'the end' => ['end'], 'the notice' => ['notice']]);
});

describe('the tables hold the rules, a NULL included (lessons 35, 162)', function () {
    it('refuses each run that breaks a CHECK, and the theme and target rows likewise', /** @param array<string, mixed> $set */ function (string $table, array $set) {
        campaignsEditor();
        $campaign = campaignsDraft();
        $run = campaignsRun($campaign);
        $category = Px::category('Kitchens');
        DB::table('content.campaign_run_targets')->insert(['run_id' => $run, 'category_id' => $category, 'brand_id' => null]);
        $now = CarbonImmutable::now();
        $set = array_map(static fn (mixed $value): mixed => $value === 'NOW' ? $now : $value, $set);

        $where = match ($table) {
            'content.campaign_runs' => ['id' => $run],
            'content.campaign_run_targets' => ['run_id' => $run],
            default => ['id' => $campaign],
        };

        expect(fn () => DB::transaction(fn () => DB::table($table)->where($where)->update($set)))->toThrow(QueryException::class);
    })->with([
        'live with no moment it went live' => ['content.campaign_runs', ['state' => 'LIVE', 'went_live_at' => null]],
        'a moment it went live on a draft' => ['content.campaign_runs', ['went_live_at' => 'NOW']],
        'ended with no moment' => ['content.campaign_runs', ['state' => 'ENDED', 'ended_at' => null]],
        'a moment ended on a draft' => ['content.campaign_runs', ['ended_at' => 'NOW']],
        'cancelled with no moment' => ['content.campaign_runs', ['state' => 'CANCELLED', 'cancelled_at' => null]],
        'choices on a scheduled run' => ['content.campaign_runs', ['state' => 'SCHEDULED', 'price_choices' => '{"lines": "BOTH"}']],
        'an alert stamped before going live' => ['content.campaign_runs', ['warned_24_at' => 'NOW']],
        'an unknown state' => ['content.campaign_runs', ['state' => 'PAUSED']],
        'a target of neither kind' => ['content.campaign_run_targets', ['category_id' => null]],
        'a blank English name' => ['content.campaigns', ['name_en' => ' ']],
        'an end before the start' => ['content.campaigns', ['ends_at_local' => '2020-01-01 00:00:00']],
    ]);
});
