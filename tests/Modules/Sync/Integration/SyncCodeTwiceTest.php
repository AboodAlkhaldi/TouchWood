<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| A code twice in one read (sync.md §2.4; owner, 2026-10-19): both values skipped, reported as a bad value
| - the size keeps its price and its stock - however far apart the two come in the read, a batch apart
| too. A code we do not have stays unknown, once.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-19 10:00:00+00');
    Queue::fake();
    FakeProvider::bind();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'secret'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * A ready product of the sizes sold in KSA, with each size's code.
 *
 * @param  list<string>  $sizes
 * @return array{variants: list<string>, codes: list<string>}
 */
function syncTwiceProduct(array $sizes): array
{
    $ready = Px::ready($sizes);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));

    return ['variants' => $ready['variants'], 'codes' => array_map(static fn (string $size): string => (string) DB::table('catalog.variants')->where('id', $size)->value('code'), $ready['variants'])];
}

/** The store's waiting read, run as its job runs it; its id. */
function syncTwiceRun(): string
{
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->orderBy('queued_at')->value('id');
    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));

    return $read;
}

it('skips both values of a code sent twice, and reports the size as a bad value with what came', function () {
    ['variants' => [$twice, $once], 'codes' => [$a, $b]] = syncTwiceProduct(['60 cm', '80 cm']);
    FakeProvider::bound()->values = [new ProviderValue($a, '120.00', 7), new ProviderValue($b, '80.00', 3), new ProviderValue(' '.$a, '85.50', 0)];

    $read = syncTwiceRun();

    expect(Rx::retail('sa', $twice))->toBeNull()
        ->and(Ix::stock('sa', $twice))->toBeNull()
        ->and(Rx::retail('sa', $once)?->amount_minor)->toBe(8000)
        ->and(DB::table('sync.read_lines')->where('read_id', $read)->where('variant_id', $twice)->count())->toBe(0)
        ->and(DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->whereNull('closed_at')->pluck('variant_id')->all())->toBe([$twice])
        ->and(json_decode((string) DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->value('detail'), true))->toEqual(['twice' => [['price' => '120.00', 'stock' => 7], ['price' => '85.50', 'stock' => 0]], 'count' => 2])
        ->and(DB::table('sync.read_reports')->where('read_id', $read)->where('kind', 'BAD_VALUE')->pluck('variant_id')->all())->toBe([$twice])
        ->and(DB::table('sync.reads')->where('id', $read)->first(['state', 'prices_changed', 'stock_changed', 'skipped']))->toMatchObject(['state' => 'DONE', 'prices_changed' => 1, 'stock_changed' => 1, 'skipped' => 1]);
});

it('skips both though the two come in different batches of the read', function () {
    ['variants' => [$twice], 'codes' => [$a]] = syncTwiceProduct(['60 cm']);
    // The first in the first batch of 500, the second in the next.
    FakeProvider::bound()->values = [
        new ProviderValue($a, '120.00', 7),
        ...array_map(static fn (int $n): ProviderValue => new ProviderValue('X'.$n, '1', 1), range(1, 600)),
        new ProviderValue($a, '85.50', 2),
    ];

    syncTwiceRun();

    expect(Rx::retail('sa', $twice))->toBeNull()
        ->and(Ix::stock('sa', $twice))->toBeNull()
        ->and(DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->whereNull('closed_at')->pluck('variant_id')->all())->toBe([$twice]);
});

it('keeps a code we do not have unknown, once, though it came twice', function () {
    FakeProvider::bound()->values = [new ProviderValue('987654', '5', 1), new ProviderValue('987654', '6', 1)];

    $read = syncTwiceRun();

    expect(DB::table('sync.report_entries')->whereNull('closed_at')->get(['kind', 'code'])->map(fn ($row): array => (array) $row)->all())->toBe([['kind' => 'UNKNOWN_CODE', 'code' => '987654']])
        ->and(DB::table('sync.read_reports')->where('read_id', $read)->count())->toBe(1);
});

it('takes a code once again in the next read, closing its bad value', function () {
    ['variants' => [$size], 'codes' => [$a]] = syncTwiceProduct(['60 cm']);
    FakeProvider::bound()->values = [new ProviderValue($a, '120.00', 7), new ProviderValue($a, '85.50', 0)];
    syncTwiceRun();
    FakeProvider::bound()->values = [new ProviderValue($a, '120.00', 7)];
    Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Now));

    syncTwiceRun();

    expect(Rx::retail('sa', $size)?->amount_minor)->toBe(12000)
        ->and(DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->whereNull('closed_at')->count())->toBe(0);
});

it('keeps the first five values of a code sent many times, and how many came (the review of step 6)', function () {
    ['codes' => [$a]] = syncTwiceProduct(['60 cm']);
    FakeProvider::bound()->values = array_map(static fn (int $n): ProviderValue => new ProviderValue($a, (string) (100 + $n), $n), range(1, 7));

    syncTwiceRun();

    $detail = json_decode((string) DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->value('detail'), true);

    expect($detail['count'])->toBe(7)
        ->and(array_column($detail['twice'], 'price'))->toBe(['101', '102', '103', '104', '105']);
});
