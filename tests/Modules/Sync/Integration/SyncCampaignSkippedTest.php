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
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Pricing\Application\PricingSettings;
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
function syncCampaignSkipped(): array
{
    $ready = Px::ready(['60 cm', '80 cm'], Px::category('Kitchens'));
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
    [$a, $b] = $ready['variants'];
    syncCampaignRead([$a => '100', $b => '100']);
    $run = strtolower((string) Str::ulid());
    app(CampaignPrices::class)->save(StoreId::fromString(Fx::storeId('sa')), $run, new CampaignPriceSetDto(CampaignLines::Both, [
        new CampaignTargetDto(CampaignTargetKind::Size, $a, amount: Money::of(7500, 'SAR')),
        new CampaignTargetDto(CampaignTargetKind::Size, $b, amount: Money::of(8000, 'SAR')),
    ]), CarbonImmutable::parse('2026-11-10 09:00:00+00')->toDateTimeImmutable(), CarbonImmutable::parse('2026-11-20 00:00:00+00')->toDateTimeImmutable());
    syncCampaignRead([$a => '120', $b => '100']);

    return ['sizes' => [$a, $b], 'run' => $run];
}

/**
 * @return array{open: bool, closedBy: string|null, run: mixed}|null
 */
function syncCampaignEntry(string $size): ?array
{
    $row = DB::table('sync.report_entries')->where('kind', 'CAMPAIGN_SKIPPED')->where('variant_id', $size)->orderByDesc('id')->first();

    return $row === null ? null : ['open' => $row->closed_at === null, 'closedBy' => $row->closed_by === null ? null : (string) $row->closed_by, 'run' => json_decode((string) $row->detail, true)['run'] ?? null];
}

it('opens an entry for the size the run skips, keeping the run\'s id, and tells staff', function () {
    Event::fake([SyncNeedsAttention::class]);
    ['sizes' => [$a, $b], 'run' => $run] = syncCampaignSkipped();

    expect(syncCampaignEntry($a))->toBe(['open' => true, 'closedBy' => null, 'run' => $run])
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
