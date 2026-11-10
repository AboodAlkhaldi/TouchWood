<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindow;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindowHandler;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Infrastructure\Queue\ApplyPriceWindowJob;
use Modules\Pricing\Public\Contracts\CampaignPrices;
use Modules\Pricing\Public\Dto\CampaignPriceSetDto;
use Modules\Pricing\Public\Dto\CampaignTargetDto;
use Modules\Pricing\Public\Enums\CampaignLines;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Public\Events\SyncNeedsAttention;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| A provider's price that makes a campaign skip a size (sync.md §9.5 amendment 2; pricing.md §9.6 #5): the
| price is taken, the run skips the size, the read opens a `CAMPAIGN_SKIPPED` entry naming the size and
| keeping the run's id, and staff are told (`SyncNeedsAttention`). The entry closes by itself the moment no
| run skips the size any more - the provider's price lowered, the ceiling raised, the run ended - on
| Pricing's `CampaignReachChecked` (closed by `PRICING`, the build's pick), or by staff.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-11-10 10:00:00+00');
    Queue::fake();
    FakeProvider::bind();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'the-secret'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, string>  $prices  size id => the provider's price
 */
function syncCampaignRead(array $prices): void
{
    FakeProvider::bound()->values = array_map(
        static fn (string $size, string $price): ProviderValue => new ProviderValue((string) DB::table('catalog.variants')->where('id', $size)->value('code'), $price, null),
        array_keys($prices),
        $prices,
    );

    if (! DB::table('sync.reads')->where('state', 'QUEUED')->exists()) {
        Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Now));
    }

    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead((string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id'))));
}

/**
 * Two sizes priced 100 by the provider, a run giving each a fixed price - 75 and 80, within the 30%
 * ceiling - then a read pricing the first at 120: 75 is 37.5% off, past it.
 *
 * @return array{sizes: list<string>, run: string}
 */
function syncCampaignSkipped(string $end = '2026-11-20 00:00:00+00'): array
{
    $ready = Px::ready(['60 cm', '80 cm'], Px::category('Kitchens'));
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
    [$a, $b] = $ready['variants'];
    syncCampaignRead([$a => '100', $b => '100']);
    $run = syncCampaignRun([$a => 7500, $b => 8000], $end);
    syncCampaignRead([$a => '120', $b => '100']);

    return ['sizes' => [$a, $b], 'run' => $run];
}

/**
 * A run in the store giving these sizes these fixed prices, from an hour ago to its end. Its id.
 *
 * @param  array<string, int>  $prices  size id => halalas
 */
function syncCampaignRun(array $prices, string $end = '2026-11-20 00:00:00+00'): string
{
    $run = strtolower((string) Str::ulid());
    app(CampaignPrices::class)->save(StoreId::fromString(Fx::storeId('sa')), $run, new CampaignPriceSetDto(CampaignLines::Both, array_map(
        static fn (string $size, int $halalas): CampaignTargetDto => new CampaignTargetDto(CampaignTargetKind::Size, $size, amount: Money::of($halalas, 'SAR')),
        array_map('strval', array_keys($prices)),
        $prices,
    )), CarbonImmutable::parse('2026-11-10 09:00:00+00')->toDateTimeImmutable(), CarbonImmutable::parse($end)->toDateTimeImmutable());

    return $run;
}

/**
 * @return array{open: bool, closedBy: string|null, runs: mixed, told: bool}|null
 */
function syncCampaignEntry(string $size): ?array
{
    $row = DB::table('sync.report_entries')->where('kind', 'CAMPAIGN_SKIPPED')->where('variant_id', $size)->orderByDesc('id')->first();

    return $row === null ? null : [
        'open' => $row->closed_at === null,
        'closedBy' => $row->closed_by === null ? null : (string) $row->closed_by,
        'runs' => json_decode((string) $row->detail, true)['runs'] ?? null,
        'told' => $row->announced_at !== null,
    ];
}

it('opens an entry for the size the run skips, keeping the run\'s id, and tells staff', function () {
    Event::fake([SyncNeedsAttention::class]);
    ['sizes' => [$a, $b], 'run' => $run] = syncCampaignSkipped();

    expect(syncCampaignEntry($a))->toBe(['open' => true, 'closedBy' => null, 'runs' => [$run], 'told' => true])
        ->and(syncCampaignEntry($b))->toBeNull()
        ->and(app(CampaignPrices::class)->skippedSizes(StoreId::fromString(Fx::storeId('sa')), $run))->toBe([$a])
        ->and(DB::table('sync.read_reports')->where('kind', 'CAMPAIGN_SKIPPED')->where('variant_id', $a)->exists())->toBeTrue();

    Event::assertDispatched(SyncNeedsAttention::class, static fn (SyncNeedsAttention $event): bool => ($event->opened['CAMPAIGN_SKIPPED'] ?? 0) === 1);
});

it('closes it by itself when the provider lowers the price so the run reaches the size again', function () {
    ['sizes' => [$a]] = syncCampaignSkipped();

    syncCampaignRead([$a => '105']);

    expect([syncCampaignEntry($a)['open'] ?? null, syncCampaignEntry($a)['closedBy'] ?? null])->toBe([false, 'PRICING']);
});

it('keeps it open while the price is still too high, and closes it when the ceiling is raised', function () {
    ['sizes' => [$a]] = syncCampaignSkipped();

    syncCampaignRead([$a => '125']);
    expect(syncCampaignEntry($a)['open'] ?? null)->toBeTrue();

    app(UpdateSettingHandler::class)->handle(new UpdateSetting(PricingSettings::MAX_DISCOUNT_PERCENT, 'sa', 45));

    expect(syncCampaignEntry($a)['closedBy'] ?? null)->toBe('PRICING');
});

it('closes it when the run ends', function () {
    ['sizes' => [$a], 'run' => $run] = syncCampaignSkipped();

    app(CampaignPrices::class)->endNow(StoreId::fromString(Fx::storeId('sa')), $run);

    expect(syncCampaignEntry($a)['closedBy'] ?? null)->toBe('PRICING');
});

it('closes it when the run reaches its end - its timed task', function () {
    ['sizes' => [$a], 'run' => $run] = syncCampaignSkipped('2026-11-10 12:00:00+00');
    $queue = Queue::getFacadeRoot();
    assert($queue instanceof QueueFake);
    $task = $queue->pushed(ApplyPriceWindowJob::class, static fn (ApplyPriceWindowJob $job): bool => $job->saleId === $run)->last();
    assert($task instanceof ApplyPriceWindowJob);
    CarbonImmutable::setTestNow('2026-11-10 12:00:00+00');

    expect(syncCampaignEntry($a)['open'] ?? null)->toBeTrue();

    Fx::asSystem(fn (): bool => app(ApplyPriceWindowHandler::class)->handle(new ApplyPriceWindow($task->storeId, $task->saleId, $task->moment)));

    expect(syncCampaignEntry($a)['closedBy'] ?? null)->toBe('PRICING');
});

it('names every run skipping the size; one rejoining leaves the entry open, naming the other', function () {
    $ready = Px::ready(['60 cm'], Px::category('Kitchens'));
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
    $a = $ready['variants'][0];
    syncCampaignRead([$a => '100']);
    $first = syncCampaignRun([$a => 7500]);
    $second = syncCampaignRun([$a => 7000]);
    $both = [$first, $second];
    sort($both);

    syncCampaignRead([$a => '120']);

    expect(syncCampaignEntry($a)['runs'] ?? null)->toBe($both);

    app(CampaignPrices::class)->endNow(StoreId::fromString(Fx::storeId('sa')), $first);

    expect(syncCampaignEntry($a))->toBe(['open' => true, 'closedBy' => null, 'runs' => [$second], 'told' => true]);
});

it('tells staff again when another run starts skipping a size already open, never when one rejoins', function () {
    ['sizes' => [$a], 'run' => $first] = syncCampaignSkipped();
    $second = syncCampaignRun([$a => 7000]);
    Event::fake([SyncNeedsAttention::class]);

    // The provider's next price: both runs past the ceiling now.
    syncCampaignRead([$a => '125']);

    $both = [$first, $second];
    sort($both);

    expect(syncCampaignEntry($a)['runs'] ?? null)->toBe($both);
    Event::assertDispatched(SyncNeedsAttention::class, static fn (SyncNeedsAttention $event): bool => ($event->opened['CAMPAIGN_SKIPPED'] ?? 0) === 1);

    // 7500 of 107 is under 30% off, 7000 is not: the first rejoins, and nothing new is told.
    Event::fake([SyncNeedsAttention::class]);
    syncCampaignRead([$a => '107']);

    expect(syncCampaignEntry($a)['runs'] ?? null)->toBe([$second]);
    Event::assertNotDispatched(SyncNeedsAttention::class);
});
