<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Public\Contracts\SyncApi;
use Modules\Sync\Public\Dto\SyncDayDto;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Sync's reads and problems per day, for Ops' daily report (sync.md amendment 1): each store's own day
| in its own time zone (a 25-hour day where the clocks go back), cut at the moment asked; a read on the
| day it finished - done, failed, or stopped by Sync itself, per kind; what the reads changed and
| skipped; the entries opened that day per kind; empty days and stores; past 90 days not complete; the
| refusals; and a fixed number of queries however many stores and days.
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Pinned before any row is made (lesson 225); nothing here signs in.
    CarbonImmutable::setTestNow('2026-10-28 12:00:00+00');
    seed(PlatformSeeder::class);
});

/** A far moment: no day asked is cut. */
const SYNC_DAYS_LATER = '2026-12-31 00:00:00+00';

function syncDaysStore(string $code): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

/**
 * A read of the store, written whole (every column Sync writes).
 *
 * @param  array{prices?: int, stock?: int, skipped?: int}  $counts
 */
function syncDaysRead(string $store, ?string $finishedAt, string $state = 'DONE', string $kind = 'HOURLY', ?string $error = null, array $counts = []): string
{
    $id = strtolower((string) Str::ulid());
    $at = $finishedAt ?? '2026-10-28 11:00:00+00';

    DB::table('sync.reads')->insert([
        'id' => $id,
        'store_id' => $store,
        'kind' => $kind,
        'state' => $state,
        'prices_changed' => $counts['prices'] ?? 0,
        'stock_changed' => $counts['stock'] ?? 0,
        'skipped' => $counts['skipped'] ?? 0,
        'error' => $error,
        'queued_at' => $at,
        'started_at' => $state === 'QUEUED' ? null : $at,
        'finished_at' => $finishedAt,
        'codes' => in_array($kind, ['NOTIFIED', 'CODE'], true) ? '["123"]' : null,
    ]);

    return $id;
}

function syncDaysLine(string $readId, string $kind): void
{
    DB::table('sync.read_lines')->insert([
        'read_id' => $readId, 'kind' => $kind, 'variant_id' => strtolower((string) Str::ulid()),
        'code' => '123', 'before' => '1', 'after' => '2',
    ]);
}

function syncDaysReported(string $readId, string $kind, ?string $variantId = null): void
{
    DB::table('sync.read_reports')->insert([
        'read_id' => $readId, 'kind' => $kind, 'code' => $kind === 'UNKNOWN_CODE' ? '999' : null,
        'variant_id' => $kind === 'UNKNOWN_CODE' ? null : $variantId, 'sale_id' => null, 'detail' => '{}',
    ]);
}

/** A report entry of the store, opened (and seen) at a moment; closed ones need not be unique. */
function syncDaysEntry(string $store, string $kind, string $openedAt, ?string $seenAt = null, bool $closed = false): void
{
    DB::table('sync.report_entries')->insert([
        'store_id' => $store,
        'kind' => $kind,
        'code' => $kind === 'UNKNOWN_CODE' ? (string) random_int(1000, 999999) : null,
        'variant_id' => in_array($kind, ['UNKNOWN_CODE', 'READ_FAILED'], true) ? null : strtolower((string) Str::ulid()),
        'sale_id' => $kind === 'SALE_STOPPED' ? strtolower((string) Str::ulid()) : null,
        'detail' => '{}',
        'opened_at' => $openedAt,
        'seen_at' => $seenAt ?? $openedAt,
        'closed_at' => $closed ? $openedAt : null,
        'closed_by' => $closed ? 'READ' : null,
    ]);
}

/**
 * @param  list<StoreId>  $stores
 * @return list<SyncDayDto>
 */
function syncDaysAsk(array $stores, string $from, string $to, string $until = SYNC_DAYS_LATER): array
{
    return app(SyncApi::class)->readsAndProblemsPerDay($stores, $from, $to, new DateTimeImmutable($until));
}

/** @param  list<SyncDayDto>  $days */
function syncDaysOne(array $days, StoreId $store, string $day): SyncDayDto
{
    foreach ($days as $answer) {
        if ($answer->storeId === $store->value && $answer->day === $day) {
            return $answer;
        }
    }

    throw new RuntimeException("No answer for {$store->value} on {$day}.");
}

function syncDaysAllReads(SyncDayDto $day): int
{
    return array_sum($day->readsDone) + array_sum($day->readsFailed) + array_sum($day->readsStopped);
}

it('counts a read on the store\'s own day, from its midnight to the next', function () {
    $sa = syncDaysStore('sa');
    // Riyadh is UTC+3: its 20 October runs from 19 October 21:00 to 20 October 21:00 UTC.
    syncDaysRead($sa->value, '2026-10-19 20:59:59+00');
    syncDaysRead($sa->value, '2026-10-19 21:00:00+00');
    syncDaysRead($sa->value, '2026-10-20 20:59:59+00');
    syncDaysRead($sa->value, '2026-10-20 21:00:00+00');

    $days = syncDaysAsk([$sa], '2026-10-19', '2026-10-21');

    expect(array_map(fn (SyncDayDto $d): array => [$d->day, syncDaysAllReads($d)], $days))
        ->toBe([['2026-10-19', 1], ['2026-10-20', 2], ['2026-10-21', 1]]);
});

it('puts the same moment on different days in stores of different time zones', function () {
    $sa = syncDaysStore('sa');
    $ae = syncDaysStore('ae');
    // 20:30 UTC: 23:30 on the 20th in Riyadh (UTC+3), 00:30 on the 21st in Dubai (UTC+4).
    syncDaysRead($sa->value, '2026-10-20 20:30:00+00');
    syncDaysRead($ae->value, '2026-10-20 20:30:00+00');

    $days = syncDaysAsk([$sa, $ae], '2026-10-20', '2026-10-21');

    expect(syncDaysAllReads(syncDaysOne($days, $sa, '2026-10-20')))->toBe(1)
        ->and(syncDaysAllReads(syncDaysOne($days, $sa, '2026-10-21')))->toBe(0)
        ->and(syncDaysAllReads(syncDaysOne($days, $ae, '2026-10-20')))->toBe(0)
        ->and(syncDaysAllReads(syncDaysOne($days, $ae, '2026-10-21')))->toBe(1);
});

it('keeps a 25-hour day whole where the clocks go back', function () {
    $eg = syncDaysStore('eg');
    // Cairo leaves summer time at the end of 29 October 2026: that day runs from 28 October 21:00 UTC
    // (UTC+3) to 29 October 22:00 UTC (UTC+2) - 25 hours.
    syncDaysRead($eg->value, '2026-10-28 20:59:59+00');
    syncDaysRead($eg->value, '2026-10-28 21:00:00+00');
    syncDaysRead($eg->value, '2026-10-29 21:30:00+00');
    syncDaysRead($eg->value, '2026-10-29 22:00:00+00');

    $days = syncDaysAsk([$eg], '2026-10-28', '2026-10-30');

    expect(array_map(fn (SyncDayDto $d): array => [$d->day, syncDaysAllReads($d)], $days))
        ->toBe([['2026-10-28', 1], ['2026-10-29', 2], ['2026-10-30', 1]]);
});

it('counts reads done, failed and stopped by Sync, per kind, and never one still waiting or running', function () {
    $sa = syncDaysStore('sa');
    $at = '2026-10-20 09:00:00+00';
    syncDaysRead($sa->value, $at, 'DONE', 'HOURLY');
    syncDaysRead($sa->value, $at, 'DONE', 'HOURLY');
    syncDaysRead($sa->value, $at, 'DONE', 'NOTIFIED');
    syncDaysRead($sa->value, $at, 'DONE', 'CODE');
    syncDaysRead($sa->value, $at, 'FAILED', 'NIGHTLY', 'The provider answered 503.');
    syncDaysRead($sa->value, $at, 'FAILED', 'NOW', 'WORKER_GONE');
    syncDaysRead($sa->value, $at, 'FAILED', 'HOURLY', 'JOB_FAILED');
    syncDaysRead($sa->value, $at, 'FAILED', 'NOW', 'UNWIRED');
    syncDaysRead($sa->value, $at, 'FAILED', 'HOURLY', 'SUPERSEDED');
    syncDaysRead($sa->value, $at, 'FAILED', 'NIGHTLY', 'STOPPED_REWIRED');
    syncDaysRead($sa->value, null, 'QUEUED', 'NOW');
    syncDaysRead($sa->value, null, 'RUNNING', 'HOURLY');

    $day = syncDaysOne(syncDaysAsk([$sa], '2026-10-20', '2026-10-20'), $sa, '2026-10-20');

    expect($day->readsDone)->toBe(['NOTIFIED' => 1, 'HOURLY' => 2, 'NIGHTLY' => 0, 'NOW' => 0, 'CODE' => 1])
        ->and($day->readsFailed)->toBe(['NOTIFIED' => 0, 'HOURLY' => 1, 'NIGHTLY' => 1, 'NOW' => 1, 'CODE' => 0])
        ->and($day->readsStopped)->toBe(['NOTIFIED' => 0, 'HOURLY' => 1, 'NIGHTLY' => 1, 'NOW' => 1, 'CODE' => 0]);
});

it('adds up what the day\'s reads changed and skipped: a done read\'s own counts, any other\'s from what it wrote', function () {
    $sa = syncDaysStore('sa');
    $at = '2026-10-20 09:00:00+00';
    // A done read's counts are its own, whatever its lines.
    $done = syncDaysRead($sa->value, $at, 'DONE', 'NIGHTLY', null, ['prices' => 5, 'stock' => 4, 'skipped' => 3]);
    syncDaysLine($done, 'PRICE');
    syncDaysRead($sa->value, $at, 'DONE', 'HOURLY', null, ['prices' => 1, 'stock' => 0, 'skipped' => 0]);
    // A failed read's are counted from what it wrote: 2 prices, 1 stock; an unknown code and one size
    // skipped twice and another once = 3 skipped.
    $failed = syncDaysRead($sa->value, $at, 'FAILED', 'NOW', 'The provider answered 503.');
    syncDaysLine($failed, 'PRICE');
    syncDaysLine($failed, 'PRICE');
    syncDaysLine($failed, 'STOCK');
    $size = strtolower((string) Str::ulid());
    syncDaysReported($failed, 'UNKNOWN_CODE');
    syncDaysReported($failed, 'BAD_VALUE', $size);
    syncDaysReported($failed, 'BAD_VALUE', $size);
    syncDaysReported($failed, 'ARCHIVED_SKIPPED', strtolower((string) Str::ulid()));
    // A stopped read's too.
    $stopped = syncDaysRead($sa->value, $at, 'FAILED', 'HOURLY', 'UNWIRED');
    syncDaysLine($stopped, 'STOCK');
    // Another day's read is not this day's.
    syncDaysRead($sa->value, '2026-10-21 09:00:00+00', 'DONE', 'HOURLY', null, ['prices' => 100, 'stock' => 100, 'skipped' => 100]);

    $day = syncDaysOne(syncDaysAsk([$sa], '2026-10-20', '2026-10-20'), $sa, '2026-10-20');

    expect([$day->pricesChanged, $day->stockChanged, $day->skipped])->toBe([5 + 1 + 2, 4 + 1 + 1, 3 + 3]);
});

it('counts the entries opened that day, per kind, every kind named', function () {
    $sa = syncDaysStore('sa');
    $eg = syncDaysStore('eg');
    syncDaysEntry($sa->value, 'UNKNOWN_CODE', '2026-10-20 08:00:00+00');
    syncDaysEntry($sa->value, 'UNKNOWN_CODE', '2026-10-20 09:00:00+00');
    syncDaysEntry($sa->value, 'SALE_STOPPED', '2026-10-20 10:00:00+00');
    // Closed since, still opened that day.
    syncDaysEntry($sa->value, 'READ_FAILED', '2026-10-20 11:00:00+00', closed: true);
    syncDaysEntry($sa->value, 'DISCOUNT_SKIPPED', '2026-10-19 21:00:00+00');
    // Opened the day before and seen again that day: not this day's.
    syncDaysEntry($sa->value, 'BAD_VALUE', '2026-10-19 20:59:59+00', '2026-10-20 10:00:00+00');
    // Another store's.
    syncDaysEntry($eg->value, 'NOT_IN_PROVIDER', '2026-10-20 09:00:00+00');

    $day = syncDaysOne(syncDaysAsk([$sa], '2026-10-20', '2026-10-20'), $sa, '2026-10-20');

    expect($day->problemsOpened)->toBe([
        'UNKNOWN_CODE' => 2, 'NOT_IN_PROVIDER' => 0, 'ARCHIVED_SKIPPED' => 0, 'OFF_OR_DRAFT_PRICED' => 0,
        'SALE_STOPPED' => 1, 'BAD_VALUE' => 0, 'READ_FAILED' => 1, 'DISCOUNT_SKIPPED' => 1,
    ]);
});

it('cuts the day at the moment asked, and leaves out a day not begun in that store', function () {
    $sa = syncDaysStore('sa');
    $ae = syncDaysStore('ae');
    syncDaysRead($sa->value, '2026-10-28 11:59:59+00');
    syncDaysEntry($sa->value, 'UNKNOWN_CODE', '2026-10-28 11:59:59+00');
    syncDaysRead($sa->value, '2026-10-28 12:00:00+00');
    syncDaysEntry($sa->value, 'UNKNOWN_CODE', '2026-10-28 12:00:00+00');

    $cut = syncDaysAsk([$sa], '2026-10-28', '2026-10-28', '2026-10-28 12:00:00+00');

    expect($cut)->toHaveCount(1)
        ->and(syncDaysAllReads($cut[0]))->toBe(1)
        ->and($cut[0]->problemsOpened['UNKNOWN_CODE'])->toBe(1);

    // 20:30 UTC: still the 28th in Riyadh, already the 29th in Dubai.
    $days = syncDaysAsk([$sa, $ae], '2026-10-28', '2026-10-31', '2026-10-28 20:30:00+00');

    expect(array_map(fn (SyncDayDto $d): string => $d->storeId === $sa->value ? 'sa '.$d->day : 'ae '.$d->day, $days))
        ->toBe(['sa 2026-10-28', 'ae 2026-10-28', 'ae 2026-10-29']);
});

it('answers every store and day asked, empty ones and an off store in zeros, in the order asked, each store once', function () {
    $sa = syncDaysStore('sa');
    $eg = syncDaysStore('eg');
    $ae = syncDaysStore('ae');
    syncDaysRead($sa->value, '2026-10-20 09:00:00+00');
    DB::table('platform.stores')->where('id', $eg->value)->update(['is_active' => false]);
    app(StoreDirectory::class)->invalidate();
    syncDaysRead($eg->value, '2026-10-21 09:00:00+00', 'DONE', 'NIGHTLY');

    $days = syncDaysAsk([$eg, $sa, $eg, $ae], '2026-10-20', '2026-10-21');

    expect(array_map(fn (SyncDayDto $d): array => [$d->storeId, $d->day], $days))->toBe([
        [$eg->value, '2026-10-20'], [$eg->value, '2026-10-21'],
        [$sa->value, '2026-10-20'], [$sa->value, '2026-10-21'],
        [$ae->value, '2026-10-20'], [$ae->value, '2026-10-21'],
    ])
        ->and(syncDaysOne($days, $eg, '2026-10-21')->readsDone['NIGHTLY'])->toBe(1);

    $empty = syncDaysOne($days, $ae, '2026-10-20');

    expect($empty->readsDone)->toBe(['NOTIFIED' => 0, 'HOURLY' => 0, 'NIGHTLY' => 0, 'NOW' => 0, 'CODE' => 0])
        ->and($empty->readsFailed)->toBe($empty->readsDone)
        ->and($empty->readsStopped)->toBe($empty->readsDone)
        ->and([$empty->pricesChanged, $empty->stockChanged, $empty->skipped])->toBe([0, 0, 0])
        ->and(array_sum($empty->problemsOpened))->toBe(0)
        ->and(array_keys($empty->problemsOpened))->toHaveCount(8)
        ->and($empty->complete)->toBeTrue()
        ->and(syncDaysAsk([], '2026-10-20', '2026-10-21'))->toBe([]);
});

it('marks a day that began more than 90 days ago not complete', function () {
    $sa = syncDaysStore('sa');
    syncDaysRead($sa->value, '2026-07-29 22:00:00+00');

    // Now is 28 October 12:00 UTC, so Sync keeps everything since 30 July 12:00 UTC. Riyadh's 30 July
    // began on 29 July 21:00 UTC, before it; its 31 July on 30 July 21:00 UTC, after it.
    $days = syncDaysAsk([$sa], '2026-07-30', '2026-07-31');

    expect(array_map(fn (SyncDayDto $d): array => [$d->day, $d->complete], $days))
        ->toBe([['2026-07-30', false], ['2026-07-31', true]])
        ->and(syncDaysAllReads($days[0]))->toBe(1);
});

it('refuses a store that does not exist and a day that is not one', function (Closure $ask, string $field) {
    $refused = null;

    try {
        $ask();
    } catch (InvalidSyncInput $e) {
        $refused = $e->attribute;
    }

    expect($refused)->toBe($field);
})->with([
    'a store that is not one' => [fn () => syncDaysAsk([StoreId::fromString(strtolower((string) Str::ulid()))], '2026-10-20', '2026-10-20'), 'store'],
    'a day that does not exist' => [fn () => syncDaysAsk([syncDaysStore('sa')], '2026-02-30', '2026-03-01'), 'day'],
    'a day not written Y-m-d' => [fn () => syncDaysAsk([syncDaysStore('sa')], '2026-10-20', '2026-10-5'), 'day'],
    'not a day at all' => [fn () => syncDaysAsk([syncDaysStore('sa')], 'today', '2026-10-20'), 'day'],
    'the first day after the last' => [fn () => syncDaysAsk([syncDaysStore('sa')], '2026-10-21', '2026-10-20'), 'day'],
]);

it('asks a fixed number of queries however many stores and days', function () {
    $stores = [syncDaysStore('sa'), syncDaysStore('eg'), syncDaysStore('ae')];

    foreach ($stores as $store) {
        foreach (['2026-09-01 09:00:00+00', '2026-09-15 09:00:00+00', '2026-10-20 09:00:00+00'] as $at) {
            syncDaysRead($store->value, $at);
            syncDaysEntry($store->value, 'UNKNOWN_CODE', $at);
        }
    }

    // Warm: the stores come from Platform's cache, whose own rows are not counted.
    syncDaysAsk([$stores[0]], '2026-10-20', '2026-10-20');
    $queries = Cx::recordQueries();

    $one = syncDaysAsk([$stores[0]], '2026-10-20', '2026-10-20');
    $afterOne = count($queries);
    $many = syncDaysAsk($stores, '2026-09-01', '2026-10-27');
    $afterMany = count($queries) - $afterOne;

    // One query over the reads, one over the entries - and the answers really came from them.
    expect($afterOne)->toBe(2)
        ->and($afterMany)->toBe(2)
        ->and(syncDaysAllReads($one[0]))->toBe(1)
        ->and($many)->toHaveCount(3 * 57)
        ->and(array_sum(array_map(fn (SyncDayDto $d): int => syncDaysAllReads($d) + array_sum($d->problemsOpened), $many)))->toBe(18);
});
