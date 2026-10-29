<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\RestoreVariant\RestoreVariant;
use Modules\Catalog\Application\Command\RestoreVariant\RestoreVariantHandler;
use Modules\Inventory\Application\Command\CountLowStock\CountLowStock;
use Modules\Inventory\Application\Command\CountLowStock\CountLowStockHandler;
use Modules\Inventory\Application\Command\PruneLowStockChanges\PruneLowStockChanges;
use Modules\Inventory\Application\Command\PruneLowStockChanges\PruneLowStockChangesHandler;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThreshold;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThresholdHandler;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\ValueObject\LowSet;
use Modules\Inventory\Infrastructure\Queue\CountLowStockJob;
use Modules\Inventory\Infrastructure\Queue\PruneLowStockChangesJob;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Inventory\Public\Dto\StockLevelDto;
use Modules\Inventory\Public\Events\LowStockChanged;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\seed;

/*
| What changed at a recount, for Ops (inventory.md §1.7, §2.1, §6.1, amendment 1): the store's low set
| kept, LowStockChanged sent after commit when a list is not empty, the change log and its read, each
| size's stock for the Stock report, and the log's nightly prune.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * Every LowStockChanged sent from now on, as a listener hears it - after the commit.
 *
 * @return ArrayObject<int, LowStockChanged>
 */
function lowChangesHeard(): ArrayObject
{
    /** @var ArrayObject<int, LowStockChanged> $heard */
    $heard = new ArrayObject;
    Event::listen(LowStockChanged::class, static function (LowStockChanged $event) use ($heard): void {
        $heard[] = $event;
    });

    return $heard;
}

/**
 * A product's sizes switched on in a store, none stocked: all of them out.
 *
 * @param  list<string>  $sizes
 * @return list<string>
 */
function lowChangesSizes(array $sizes = ['60 cm', '80 cm', '100 cm'], string $store = 'sa'): array
{
    $ready = Px::ready($sizes);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId($store), $ready['product'], true)));

    return $ready['variants'];
}

function lowChangesRecount(string $store = 'sa'): void
{
    Fx::asSystem(fn () => app(CountLowStockHandler::class)->handle(new CountLowStock(Ix::storeId($store))));
}

/**
 * The store's kept set, as "low:[…] out:[…]", or null when it has none.
 */
function lowChangesKept(string $store = 'sa'): ?LowSet
{
    $row = DB::table('inventory.low_sets')->where('store_id', Ix::storeId($store))->first();

    if ($row === null) {
        return null;
    }

    /** @var list<string> $low */
    $low = json_decode((string) $row->low, true, flags: JSON_THROW_ON_ERROR);
    /** @var list<string> $out */
    $out = json_decode((string) $row->out, true, flags: JSON_THROW_ON_ERROR);

    return new LowSet($low, $out);
}

/**
 * The store's log, oldest first, as "CHANGE size".
 *
 * @return list<string>
 */
function lowChangesLog(string $store = 'sa'): array
{
    return array_values(array_map(
        static fn (stdClass $row): string => "{$row->change} {$row->variant_id}",
        DB::table('inventory.low_changes')->where('store_id', Ix::storeId($store))->orderBy('id')->get()->all(),
    ));
}

/**
 * @param  list<string>  $ids
 * @return list<string>
 */
function lowChangesSorted(array $ids): array
{
    sort($ids, SORT_STRING);

    return $ids;
}

/**
 * @return array{list<string>, list<string>, list<string>}
 */
function lowChangesLists(LowStockChanged $event): array
{
    return [$event->becameLow, $event->ranOut, $event->cameBack];
}

function lowChangesEntry(string $store, string $variantId, string $change, CarbonImmutable $at): void
{
    DB::table('inventory.low_changes')->insert(['store_id' => Ix::storeId($store), 'variant_id' => $variantId, 'change' => $change, 'changed_at' => $at->format('Y-m-d H:i:s.uP')]);
}

describe('the recount', function () {
    it('keeps a store\'s first set silently: no event, nothing logged', function () {
        $heard = lowChangesHeard();
        [$a, $b, $c] = lowChangesSizes();

        expect(lowChangesKept()?->out)->toBe(lowChangesSorted([$a, $b, $c]))
            ->and(lowChangesKept()?->low)->toBe([])
            ->and($heard)->toHaveCount(0)
            ->and(lowChangesLog())->toBe([]);
    });

    it('says each change once: came back, became low, ran out (LOW to OUT), nothing for OUT to LOW, came back from low', function () {
        [$a] = lowChangesSizes();
        $heard = lowChangesHeard();

        // Out -> neither (the store's default threshold is 10).
        Ix::count('sa', [$a => 50]);
        // Neither -> low.
        Ix::count('sa', [$a => 5]);
        // Low -> out.
        Ix::count('sa', [$a => 0]);
        // Out -> low: nothing.
        Ix::count('sa', [$a => 4]);
        // Low -> neither.
        Ix::count('sa', [$a => 11]);

        expect(array_map(lowChangesLists(...), (array) $heard))->toBe([
            [[], [], [$a]],
            [[$a], [], []],
            [[], [$a], []],
            [[], [], [$a]],
        ])
            ->and(lowChangesLog())->toBe(["BACK {$a}", "LOW {$a}", "OUT {$a}", "BACK {$a}"])
            ->and(array_unique(array_map(static fn (LowStockChanged $event): string => $event->storeId, (array) $heard)))->toBe([Ix::storeId('sa')])
            ->and(count(array_unique(array_map(static fn (LowStockChanged $event): string => $event->eventId, (array) $heard))))->toBe(4);
    });

    it('says a size new to the list with nothing free ran out, not became low', function () {
        lowChangesSizes();
        $heard = lowChangesHeard();
        [$d] = lowChangesSizes(['120 cm']);

        expect(array_map(lowChangesLists(...), (array) $heard))->toBe([[[], [$d], []]]);
    });

    it('counts a size held beyond its stock, and a threshold of 0 at 0, as out', function () {
        [$a, $b] = lowChangesSizes();
        Ix::count('sa', [$a => 50, $b => 50]);
        $heard = lowChangesHeard();

        Fx::asSystem(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold(Ix::storeId('sa'), [$b => 0])));
        Ix::count('sa', [$b => 0]);
        // 20 held, 30 free: nothing; then a count of 10 leaves it Short - nothing free.
        app(InventoryApi::class)->hold(StoreId::fromString(Ix::storeId('sa')), strtolower((string) Str::ulid()), [new HoldLineDto($a, 20)], null);
        Ix::count('sa', [$a => 10]);

        expect(array_map(lowChangesLists(...), (array) $heard))->toBe([[[], [$b], []], [[], [$a], []]]);
    });

    it('sends nothing when nothing changed, and nothing twice', function () {
        [$a] = lowChangesSizes();
        Ix::count('sa', [$a => 5]);
        $heard = lowChangesHeard();
        $log = lowChangesLog();

        // Still low.
        Ix::count('sa', [$a => 6]);
        lowChangesRecount();
        lowChangesRecount();

        expect($heard)->toHaveCount(0)
            ->and(lowChangesLog())->toBe($log);
    });

    it('sends one event a store a recount, with every change in its list, each in id order', function () {
        [$a, $b, $c] = lowChangesSizes();
        Ix::count('sa', [$a => 5, $b => 50]);
        $heard = lowChangesHeard();
        // The changes wait for one recount, as a burst does on a worker.
        Queue::fake();

        Ix::count('sa', [$a => 0, $b => 3, $c => 40]);
        Ix::count('sa', [$c => 4]);
        lowChangesRecount();

        $changed = $heard[0] ?? null;

        expect($heard)->toHaveCount(1)
            ->and($changed?->becameLow)->toBe([$b])
            ->and($changed?->ranOut)->toBe([$a])
            ->and($changed?->cameBack)->toBe([])
            ->and(lowChangesKept()?->low)->toBe(lowChangesSorted([$b, $c]))
            ->and(lowChangesKept()?->out)->toBe([$a]);
    });

    it('lets an archived size leave silently, and says it again when it comes back still low', function () {
        [$a, $b] = lowChangesSizes();
        Ix::count('sa', [$a => 50, $b => 5]);
        $heard = lowChangesHeard();

        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($b)));

        expect($heard)->toHaveCount(0)
            ->and(lowChangesKept()?->low)->toBe([]);

        Fx::asSystem(fn () => app(RestoreVariantHandler::class)->handle(new RestoreVariant($b)));

        expect(array_map(lowChangesLists(...), (array) $heard))->toBe([[[$b], [], []]]);
    });

    it('lets a size switched off with no stock row leave silently', function () {
        $product = Px::ready(['60 cm', '80 cm']);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $product['product'], true)));
        $heard = lowChangesHeard();

        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $product['product'], false)));

        expect($heard)->toHaveCount(0)
            ->and(lowChangesKept()?->out)->toBe([]);
    });

    it('sends its event only once the recount commits, and nothing from a recount rolled back', function () {
        $sizes = lowChangesSizes();
        [$a] = $sizes;
        Queue::fake();
        Ix::count('sa', [$a => 50]);
        $heard = lowChangesHeard();
        $levels = [];
        Event::listen(LowStockChanged::class, static function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        try {
            DB::transaction(function () use ($heard): void {
                lowChangesRecount();

                expect($heard)->toHaveCount(0);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        expect($heard)->toHaveCount(0)
            ->and(lowChangesLog())->toBe([])
            ->and(lowChangesKept()?->out)->toBe(lowChangesSorted($sizes));

        lowChangesRecount();

        // Heard at RefreshDatabase's level, after the recount's own transaction committed.
        expect($heard)->toHaveCount(1)
            ->and($levels)->toBe([1]);
    });

    it('runs a store\'s recounts one at a time: its own lock first in its transaction, the kept set and the list read under it', function () {
        [$a] = lowChangesSizes();
        $store = Ix::storeId('sa');
        $queries = Cx::recordQueries();

        lowChangesRecount();

        // Level 2: the recount's transaction under RefreshDatabase's.
        $inside = array_values(array_filter((array) $queries, static fn (array $query): bool => $query['level'] >= 2));
        $readsBefore = array_values(array_filter((array) $queries, static fn (array $query): bool => $query['level'] < 2
            && (str_contains($query['sql'], 'low_sets') || str_contains($query['sql'], '"inventory"."stock"') || str_contains($query['sql'], '"catalog".'))));

        expect($inside)->not->toBeEmpty()
            ->and($inside[0]['sql'])->toContain('pg_advisory_xact_lock')
            ->and($inside[0]['bindings'])->toBe(["inventory:low_set:{$store}"])
            ->and($inside[1]['sql'])->toContain('FROM inventory.low_sets')
            ->and($readsBefore)->toBe([])
            ->and(array_filter($inside, static fn (array $query): bool => str_contains($query['sql'], '"inventory"."stock"')))->not->toBeEmpty()
            // Never the stock's own lock: no change of stock waits for a recount.
            ->and(array_filter((array) $queries, static fn (array $query): bool => in_array("inventory:store:{$store}", $query['bindings'], true)))->toBe([]);

        // Two recounts after one change: the second, under the lock, reads the first's set and says nothing.
        $heard = lowChangesHeard();
        Queue::fake();
        Ix::count('sa', [$a => 50]);
        lowChangesRecount();
        lowChangesRecount();

        expect($heard)->toHaveCount(1);
    });

    it('keeps the count and the set in step, from one read', function () {
        [$a, $b] = lowChangesSizes();
        Ix::count('sa', [$a => 5, $b => 50]);

        $kept = lowChangesKept();

        expect((int) DB::table('inventory.low_counts')->where('store_id', Ix::storeId('sa'))->value('low'))->toBe(count($kept->low ?? []) + count($kept->out ?? []))
            ->and($kept?->low)->toBe([$a]);
    });
});

describe('lowStockChanges', function () {
    it('answers each kind\'s sizes in [from, to), once each, in id order, a size in more than one list, one store only, in one query', function () {
        [$x, $y, $z] = array_map(static fn (): string => strtolower((string) Str::ulid()), [1, 2, 3]);
        $w = strtolower((string) Str::ulid());
        $from = CarbonImmutable::parse('2026-05-01 00:00:00', 'Asia/Riyadh');
        $to = $from->addDay();

        lowChangesEntry('sa', $x, 'LOW', $from);
        lowChangesEntry('sa', $x, 'OUT', $from->addHour());
        lowChangesEntry('sa', $x, 'BACK', $to->subMicrosecond());
        lowChangesEntry('sa', $y, 'LOW', $to);
        lowChangesEntry('sa', $y, 'OUT', $from->subMicrosecond());
        lowChangesEntry('sa', $z, 'LOW', $from->addHours(2));
        lowChangesEntry('sa', $z, 'LOW', $from->addHours(3));
        lowChangesEntry('ae', $w, 'LOW', $from->addHour());

        $api = app(InventoryApi::class);
        $sa = StoreId::fromString(Ix::storeId('sa'));
        // Warm, as stockLevels is measured: Platform keeps what it read of the store.
        $api->lowStockChanges($sa, $from->toDateTimeImmutable(), $to->toDateTimeImmutable());
        $queries = Cx::recordQueries();
        $changes = $api->lowStockChanges($sa, $from->toDateTimeImmutable(), $to->toDateTimeImmutable());

        expect($changes->becameLow)->toBe(lowChangesSorted([$x, $z]))
            ->and($changes->ranOut)->toBe([$x])
            ->and($changes->cameBack)->toBe([$x])
            // Warm: the log; the store's existence answered from what Platform keeps (3 queries cold).
            ->and($queries)->toHaveCount(1);

        $other = $api->lowStockChanges(StoreId::fromString(Ix::storeId('ae')), $from->toDateTimeImmutable(), $to->toDateTimeImmutable());

        expect([$other->becameLow, $other->ranOut, $other->cameBack])->toBe([[$w], [], []]);
    });

    it('answers an empty range with nothing, and throws on a reversed one', function () {
        $x = strtolower((string) Str::ulid());
        $at = CarbonImmutable::parse('2026-05-01 10:00:00', 'UTC');
        lowChangesEntry('sa', $x, 'LOW', $at);
        $store = StoreId::fromString(Ix::storeId('sa'));
        $api = app(InventoryApi::class);

        $empty = $api->lowStockChanges($store, $at->toDateTimeImmutable(), $at->toDateTimeImmutable());

        expect([$empty->becameLow, $empty->ranOut, $empty->cameBack])->toBe([[], [], []])
            ->and(fn () => $api->lowStockChanges($store, $at->toDateTimeImmutable(), $at->subMicrosecond()->toDateTimeImmutable()))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a store that does not exist, as stockLevels does', function () {
        $at = CarbonImmutable::now()->toDateTimeImmutable();

        expect(fn () => app(InventoryApi::class)->lowStockChanges(StoreId::fromString(strtolower((string) Str::ulid())), $at, $at))
            ->toThrow(InvalidInventoryInput::class);
    });

    it('stamps each change as it is written, after the recount started, so a report read meanwhile cannot miss it', function () {
        [$a] = lowChangesSizes();
        $heard = lowChangesHeard();

        Ix::count('sa', [$a => 50]);

        // The set keeps the recount's start (to the microsecond); the change and its event the later write.
        $started = CarbonImmutable::parse((string) DB::table('inventory.low_sets')->where('store_id', Ix::storeId('sa'))->value('counted_at'));
        $stamped = CarbonImmutable::parse((string) DB::table('inventory.low_changes')->where('store_id', Ix::storeId('sa'))->value('changed_at'));
        $event = $heard[0] ?? null;

        expect($stamped->greaterThan($started))->toBeTrue()
            ->and($event?->occurredAt->format('Y-m-d H:i:s.u'))->toBe($stamped->format('Y-m-d H:i:s.u'));
    });

    it('reads what the recounts wrote', function () {
        [$a, $b] = lowChangesSizes();
        $from = CarbonImmutable::now()->subSecond();

        Ix::count('sa', [$a => 50, $b => 50]);

        $changes = app(InventoryApi::class)->lowStockChanges(StoreId::fromString(Ix::storeId('sa')), $from->toDateTimeImmutable(), CarbonImmutable::now()->addSecond()->toDateTimeImmutable());

        expect([$changes->becameLow, $changes->ranOut, $changes->cameBack])->toBe([[], [], lowChangesSorted([$a, $b])]);
    });
});

describe('stockLevels', function () {
    it('pages each size the store lists in id order, with its figures, low and out as the list says', function () {
        [$a, $b, $c] = lowChangesSizes();
        Ix::count('sa', [$a => 50, $b => 5]);
        Fx::asSystem(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold(Ix::storeId('sa'), [$a => 60])));
        app(InventoryApi::class)->hold(StoreId::fromString(Ix::storeId('sa')), strtolower((string) Str::ulid()), [new HoldLineDto($a, 2)], null);
        $store = StoreId::fromString(Ix::storeId('sa'));
        $api = app(InventoryApi::class);

        $first = $api->stockLevels($store, null, 2);
        $second = $api->stockLevels($store, $first->next, 2);
        $ids = lowChangesSorted([$a, $b, $c]);

        expect(array_map(static fn (StockLevelDto $row): string => $row->variantId, $first->rows))->toBe(array_slice($ids, 0, 2))
            ->and($first->next)->toBe($ids[1])
            ->and(array_map(static fn (StockLevelDto $row): string => $row->variantId, $second->rows))->toBe([$ids[2]])
            ->and($second->next)->toBeNull();

        $rows = [];

        foreach ([...$first->rows, ...$second->rows] as $row) {
            $rows[$row->variantId] = [$row->inStock, $row->returnedExtra, $row->held, $row->available, $row->threshold, $row->countsOnStock, $row->archived, $row->low, $row->out];
        }

        expect($rows[$a])->toBe([50, 0, 2, 48, 60, true, false, true, false])
            ->and($rows[$b])->toBe([5, 0, 0, 5, 10, true, false, true, false])
            ->and($rows[$c])->toBe([0, 0, 0, 0, 10, true, false, false, true]);

        // Archived with a stock row (still listed): neither low nor out, as the list leaves it off. A size
        // with no row stops being listed when archived, as Catalog no longer has it switched on.
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($b)));
        $archived = array_values(array_filter($api->stockLevels($store, null, 10)->rows, static fn (StockLevelDto $row): bool => $row->variantId === $b))[0] ?? null;

        expect([$archived?->archived, $archived?->low, $archived?->out])->toBe([true, false, false]);
    });

    it('answers a wired store\'s returned extra and switch', function () {
        [$a] = lowChangesSizes();
        Ix::count('sa', [$a => 20]);
        Ix::wired();
        Ix::switchOff('sa', [$a]);
        DB::table('inventory.stock')->where('store_id', Ix::storeId('sa'))->where('variant_id', $a)->update(['returned_extra' => 4]);

        $row = array_values(array_filter(app(InventoryApi::class)->stockLevels(StoreId::fromString(Ix::storeId('sa')))->rows, static fn (StockLevelDto $row): bool => $row->variantId === $a))[0] ?? null;

        expect([$row?->inStock, $row?->returnedExtra, $row?->available, $row?->countsOnStock])->toBe([20, 4, 24, false]);
    });

    it('reads in the same number of queries for one size as for many, and refuses a store that does not exist', function () {
        lowChangesSizes();
        $store = StoreId::fromString(Ix::storeId('sa'));
        $api = app(InventoryApi::class);
        $api->stockLevels($store, null, 1);

        $one = Cx::recordQueries();
        $api->stockLevels($store, null, 1);
        $counted = count($one);

        $many = Cx::recordQueries();
        $api->stockLevels($store, null, 1000);

        expect($counted)->toBeGreaterThan(0)
            ->and(count($many))->toBe($counted)
            // The store known, its rows, the sizes switched on, the page's sizes in brief.
            ->and($counted)->toBe(4)
            ->and(fn () => $api->stockLevels(StoreId::fromString(strtolower((string) Str::ulid()))))->toThrow(InvalidInventoryInput::class);
    });
});

describe('the log\'s prune', function () {
    it('removes entries older than twelve months, keeps the rest, and is the system\'s alone', function () {
        $old = strtolower((string) Str::ulid());
        $kept = strtolower((string) Str::ulid());
        lowChangesEntry('sa', $old, 'LOW', CarbonImmutable::now()->subMonthsNoOverflow(12)->subMinute());
        lowChangesEntry('sa', $kept, 'LOW', CarbonImmutable::now()->subMonthsNoOverflow(12)->addMinute());

        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['sa']);

        expect(fn () => app(PruneLowStockChangesHandler::class)->handle(new PruneLowStockChanges))->toThrow(Unauthorized::class)
            ->and(Fx::asSystem(fn (): int => app(PruneLowStockChangesHandler::class)->handle(new PruneLowStockChanges)))->toBe(1)
            ->and(lowChangesLog())->toBe(["LOW {$kept}"]);
    });

    it('runs each night at 04:00 Riyadh time on one server, its lock its own', function () {
        $events = collect(app(Schedule::class)->events());
        $prune = $events->filter(static fn ($event): bool => $event->description === PruneLowStockChangesJob::class)->values();
        $count = $events->first(static fn ($event): bool => $event->description === CountLowStockJob::class);
        $night = $prune->first();
        assert($night instanceof ScheduledEvent && $count instanceof ScheduledEvent);

        expect($prune)->toHaveCount(1)
            ->and([$night->expression, $night->timezone, $night->onOneServer])->toBe(['0 4 * * *', 'Asia/Riyadh', true])
            ->and($night->mutexName())->not->toBe($count->mutexName());
    });
});

it('refuses a change the log does not know, and a set that is not two lists', function () {
    $store = Ix::storeId('sa');

    // Each in a savepoint of its own: a refused statement spoils the transaction it runs in.
    expect(fn () => DB::transaction(fn () => DB::table('inventory.low_changes')->insert(['store_id' => $store, 'variant_id' => strtolower((string) Str::ulid()), 'change' => 'GONE', 'changed_at' => now()])))
        ->toThrow(QueryException::class, 'low_changes_change')
        ->and(fn () => DB::transaction(fn () => DB::table('inventory.low_sets')->insert(['store_id' => $store, 'low' => '{}', 'out' => '[]', 'counted_at' => now()])))
        ->toThrow(QueryException::class, 'low_sets_lists')
        ->and(fn () => DB::transaction(fn () => DB::table('inventory.low_sets')->insert(['store_id' => $store, 'low' => '[]', 'out' => '"x"', 'counted_at' => now()])))
        ->toThrow(QueryException::class, 'low_sets_lists');
});
