<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Sync\Application\Command\DismissEntry\DismissEntry;
use Modules\Sync\Application\Command\DismissEntry\DismissEntryHandler;
use Modules\Sync\Application\Command\PruneSyncLog\PruneSyncLog;
use Modules\Sync\Application\Command\PruneSyncLog\PruneSyncLogHandler;
use Modules\Sync\Application\Command\RetryEntry\RetryEntry;
use Modules\Sync\Application\Command\RetryEntry\RetryEntryHandler;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\SyncNow\SyncNow;
use Modules\Sync\Application\Command\SyncNow\SyncNowHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Application\Query\CountSyncReport\CountSyncReport;
use Modules\Sync\Application\Query\CountSyncReport\CountSyncReportHandler;
use Modules\Sync\Application\Query\ListSyncReads\ListSyncReads;
use Modules\Sync\Application\Query\ListSyncReads\ListSyncReadsHandler;
use Modules\Sync\Application\Query\ViewSyncRead\ViewSyncRead;
use Modules\Sync\Application\Query\ViewSyncRead\ViewSyncReadHandler;
use Modules\Sync\Application\Query\ViewSyncReport\ViewSyncReport;
use Modules\Sync\Application\Query\ViewSyncReport\ViewSyncReportHandler;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\EntryNotDismissable;
use Modules\Sync\Domain\Exception\EntryNotFound;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\NotWired;
use Modules\Sync\Domain\Exception\ReadNotFound;
use Modules\Sync\Domain\Exception\SizeNotFound;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Modules\Sync\Infrastructure\Queue\PruneSyncLogJob;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| What staff read and do in Sync (sync.md §1.6, §3; owner, 2026-10-17, §9.3 #19): the report and its
| count, the page of reads and each read's sections; Sync now, Retry and Dismiss / Leave it, each for the
| store's holders of its permission; the log kept 90 days.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-17 10:00:00+00');
    Queue::fake();
    FakeProvider::bind();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'secret'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * A product of two sizes sold in KSA, read once: the first priced 120.50 and stocked 7, the second sent
 * at 0, and a code we do not have.
 *
 * @return array{product: string, sizes: list<string>, read: string}
 */
function syncReportRead(): array
{
    $ready = Px::ready(['60 cm', '80 cm']);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
    $code = static fn (string $size): string => (string) DB::table('catalog.variants')->where('id', $size)->value('code');
    FakeProvider::bound()->values = [new ProviderValue($code($ready['variants'][0]), '120.50', 7), new ProviderValue($code($ready['variants'][1]), '0', null), new ProviderValue('987654', '5', 1)];
    $read = (string) DB::table('sync.reads')->value('id');
    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));

    return ['product' => $ready['product'], 'sizes' => $ready['variants'], 'read' => $read];
}

function syncReportViewer(): string
{
    return Fx::staffWith([SyncPermissions::REPORT_VIEW], ['sa']);
}

describe('the page of reads (§9.3 #19)', function () {
    it('lists the store\'s reads, the latest first, with their counts', function () {
        ['read' => $read] = syncReportRead();
        Fx::actAsStaff(syncReportViewer());

        $page = app(ListSyncReadsHandler::class)->handle(new ListSyncReads(Fx::storeId('sa')));

        expect($page->total)->toBe(1)
            ->and($page->reads[0]->id)->toBe($read)
            ->and([$page->reads[0]->kind, $page->reads[0]->state, $page->reads[0]->pricesChanged, $page->reads[0]->stockChanged, $page->reads[0]->skipped])->toBe(['NOW', 'DONE', 1, 1, 2]);
    });

    it('opens a read on its sections: prices and stock changed, from and to, and what it reported - each naming its product', function () {
        ['product' => $product, 'sizes' => [$first, $second], 'read' => $read] = syncReportRead();
        Fx::actAsStaff(syncReportViewer());

        app()->setLocale('en');
        $prices = app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read));
        $stock = app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::STOCK));
        $reported = app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::REPORTED));

        $code = (string) DB::table('catalog.variants')->where('id', $first)->value('code');

        // A price written as Pricing writes money (the store's decimals and sign), a stock number as it is.
        expect(array_map(static fn ($line): array => [$line->variantId, $line->code, $line->productId, $line->before, $line->after], $prices->lines))->toBe([[$first, $code, $product, null, "120.50 \u{20C1}"]])
            ->and($prices->sections)->toBe(['prices' => 1, 'stock' => 1, 'reported' => 2, 'codes' => 0])
            ->and(array_map(static fn ($line): array => [$line->variantId, $line->before, $line->after], $stock->lines))->toBe([[$first, '0', '7']])
            ->and($prices->lines[0]->productNameEn)->not->toBeNull()
            ->and($prices->lines[0]->values)->toBe(['60 cm'])
            ->and([$prices->reported, $stock->reported, $reported->lines])->toBe([[], [], []])
            ->and(array_map(static fn ($entry): string => $entry->kind.' '.($entry->productId ?? $entry->code), $reported->reported))->toEqualCanonicalizing(["BAD_VALUE {$product}", 'UNKNOWN_CODE 987654']);
    });

    it('shows a read to whoever may see its store\'s report; to anyone else it is not there (owner, 2026-10-18)', function () {
        ['read' => $read] = syncReportRead();
        Fx::actAsStaff(Fx::staffWith([SyncPermissions::REPORT_VIEW], ['ae']));

        expect(fn () => app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read)))->toThrow(ReadNotFound::class)
            ->and(fn () => app(ViewSyncReadHandler::class)->handle(new ViewSyncRead('01jbzzzzzzzzzzzzzzzzzzzzzz')))->toThrow(ReadNotFound::class);
    });
});

describe('what a read reported, as it was that day (owner, 2026-10-18)', function () {
    it('keeps each read\'s own list, though a later read sees the same again and a staff member dismisses it', function () {
        ['read' => $first] = syncReportRead();
        DB::table('sync.report_entries')->where('kind', 'UNKNOWN_CODE')->update(['closed_at' => now(), 'closed_by' => 'READ']);
        Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Now));
        $second = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');
        Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($second)));
        Fx::actAsStaff(syncReportViewer());

        $kinds = static fn (string $read): array => array_map(static fn ($entry): string => $entry->kind, app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::REPORTED))->reported);

        expect($kinds($first))->toEqualCanonicalizing(['BAD_VALUE', 'UNKNOWN_CODE'])
            ->and($kinds($second))->toEqualCanonicalizing(['BAD_VALUE', 'UNKNOWN_CODE']);
    });

    it('pages each section, so a read of thousands is never one page', function () {
        ['read' => $read] = syncReportRead();
        DB::table('sync.read_lines')->insert(array_map(static fn (int $n): array => ['read_id' => $read, 'kind' => 'STOCK', 'variant_id' => strtolower((string) Str::ulid()), 'before' => '0', 'after' => (string) $n], range(1, 150)));
        Fx::actAsStaff(syncReportViewer());

        $first = app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::STOCK));
        $second = app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::STOCK, 2));

        expect([$first->sections['stock'], count($first->lines), count($second->lines), $first->perPage, $first->more, $second->more])->toBe([151, 100, 51, 100, true, false]);
    });
});

describe('the report (§1.6)', function () {
    it('shows the open entries, the latest seen first, naming each size\'s product or the provider\'s code - one kind or all - and counts them', function () {
        ['product' => $product] = syncReportRead();
        Fx::actAsStaff(syncReportViewer());

        $all = app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(Fx::storeId('sa')));
        $unknown = app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(Fx::storeId('sa'), 'UNKNOWN_CODE'));

        // The latest seen first; a closed entry and another store's never shown or counted.
        DB::table('sync.report_entries')->where('kind', 'UNKNOWN_CODE')->update(['seen_at' => now()->addMinute()]);
        DB::table('sync.report_entries')->insert([
            ['store_id' => Fx::storeId('sa'), 'kind' => 'UNKNOWN_CODE', 'code' => '111', 'variant_id' => null, 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now(), 'closed_at' => now(), 'closed_by' => 'READ'],
            ['store_id' => Fx::storeId('ae'), 'kind' => 'UNKNOWN_CODE', 'code' => '222', 'variant_id' => null, 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now(), 'closed_at' => null, 'closed_by' => null],
        ]);
        $all = app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(Fx::storeId('sa')));

        expect($all->total)->toBe(2)
            ->and(array_map(static fn ($entry): string => $entry->about->kind.' '.($entry->about->productId ?? $entry->about->code), $all->entries))->toBe(['UNKNOWN_CODE 987654', "BAD_VALUE {$product}"])
            ->and(array_map(static fn ($entry): string => (string) $entry->about->code, $unknown->entries))->toBe(['987654'])
            ->and(app(CountSyncReportHandler::class)->handle(new CountSyncReport(Fx::storeId('sa'))))->toBe(2);
    });

    it('is for the store\'s holders of View the Sync Report, and refuses a kind that is not one', function () {
        syncReportRead();
        Fx::actAsStaff(Fx::staffWith([SyncPermissions::REPORT_VIEW], ['ae']));

        expect(fn () => app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(Fx::storeId('sa'))))->toThrow(Unauthorized::class)
            ->and(fn () => app(CountSyncReportHandler::class)->handle(new CountSyncReport(Fx::storeId('sa'))))->toThrow(Unauthorized::class)
            ->and(fn () => app(ListSyncReadsHandler::class)->handle(new ListSyncReads('nope')))->toThrow(Unauthorized::class);

        Fx::actAsStaff(syncReportViewer());

        expect(fn () => app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(Fx::storeId('sa'), 'NOTHING')))->toThrow(InvalidSyncInput::class);
    });
});

describe('the actions (§3: Run Sync Reads)', function () {
    it('reads the store now, asked by whoever pressed it - one waiting at most', function () {
        DB::table('sync.reads')->delete();
        $runner = Fx::staffWith([SyncPermissions::READ_RUN], ['sa']);
        Fx::actAsStaff($runner);

        app(SyncNowHandler::class)->handle(new SyncNow(Fx::storeId('sa')));
        app(SyncNowHandler::class)->handle(new SyncNow(Fx::storeId('sa')));

        expect(DB::table('sync.reads')->get(['kind', 'requested_by_id'])->map(fn ($read) => (array) $read)->all())->toBe([['kind' => 'NOW', 'requested_by_id' => $runner]]);
    });

    it('refuses Sync now in a store not wired, and to whoever may not run its reads', function () {
        Fx::actAsStaff(Fx::staffWith([SyncPermissions::READ_RUN], ['ae']));

        expect(fn () => app(SyncNowHandler::class)->handle(new SyncNow(Fx::storeId('ae'))))->toThrow(NotWired::class)
            ->and(fn () => app(SyncNowHandler::class)->handle(new SyncNow(Fx::storeId('sa'))))->toThrow(Unauthorized::class);

        Fx::actAsStaff(syncReportViewer());

        expect(fn () => app(SyncNowHandler::class)->handle(new SyncNow(Fx::storeId('sa'))))->toThrow(Unauthorized::class);
    });

    it('retries an entry: the provider\'s code, or the size\'s own today; a failed read\'s, the whole store', function () {
        ['sizes' => [, $second]] = syncReportRead();
        Fx::actAsStaff(Fx::staffWith([SyncPermissions::READ_RUN], ['sa']));
        $entry = static fn (string $kind): int => (int) DB::table('sync.report_entries')->where('kind', $kind)->value('id');
        DB::table('sync.report_entries')->insert(['store_id' => Fx::storeId('sa'), 'kind' => 'READ_FAILED', 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now()]);

        app(RetryEntryHandler::class)->handle(new RetryEntry($entry('UNKNOWN_CODE')));
        app(RetryEntryHandler::class)->handle(new RetryEntry($entry('BAD_VALUE')));
        app(RetryEntryHandler::class)->handle(new RetryEntry($entry('READ_FAILED')));

        $code = (string) DB::table('catalog.variants')->where('id', $second)->value('code');

        expect(DB::table('sync.reads')->where('state', 'QUEUED')->orderBy('kind')->get(['kind', 'codes'])->map(fn ($read) => $read->kind.' '.$read->codes)->all())
            ->toBe(['CODE ["'.$code.'", "987654"]', 'NOW ']);
    });

    it('dismisses an entry - Leave it is the same - closed by whoever pressed it and audited; an entry not open is not found', function () {
        syncReportRead();
        $staff = Fx::staffWith([SyncPermissions::READ_RUN], ['sa']);
        Fx::actAsStaff($staff);
        $entry = (int) DB::table('sync.report_entries')->where('kind', 'UNKNOWN_CODE')->value('id');

        app(DismissEntryHandler::class)->handle(new DismissEntry($entry));

        expect(DB::table('sync.report_entries')->where('id', $entry)->value('closed_by'))->toBe('STAFF:'.$staff)
            ->and(Fx::audits('sync.entry.dismissed', (string) $entry))->toBe(1)
            ->and(fn () => app(DismissEntryHandler::class)->handle(new DismissEntry($entry)))->toThrow(EntryNotFound::class)
            ->and(fn () => app(RetryEntryHandler::class)->handle(new RetryEntry($entry)))->toThrow(EntryNotFound::class);
    });

    it('refuses an entry\'s action to its store\'s viewer; to a runner elsewhere the entry is not there (owner, 2026-10-18)', function () {
        syncReportRead();
        $entry = (int) DB::table('sync.report_entries')->value('id');

        foreach ([[syncReportViewer(), Unauthorized::class], [Fx::staffWith([SyncPermissions::READ_RUN], ['ae']), EntryNotFound::class]] as [$staff, $error]) {
            Fx::actAsStaff($staff);

            expect(fn () => app(DismissEntryHandler::class)->handle(new DismissEntry($entry)))->toThrow($error)
                ->and(fn () => app(RetryEntryHandler::class)->handle(new RetryEntry($entry)))->toThrow($error);
        }

        Fx::actAsStaff(Fx::staffWith([SyncPermissions::REPORT_VIEW], ['ae']));

        expect(fn () => app(ListSyncReadsHandler::class)->handle(new ListSyncReads(Fx::storeId('sa'))))->toThrow(Unauthorized::class);
    });

    it('tells the second of two pressing Dismiss at once that the entry is gone, and audits it once', function () {
        syncReportRead();
        $entry = (int) DB::table('sync.report_entries')->where('kind', 'UNKNOWN_CODE')->value('id');
        // Closed by another between this one's lookup and its close.
        $real = app(ReportEntries::class);
        app()->instance(ReportEntries::class, new class($real) implements ReportEntries
        {
            public function __construct(private ReportEntries $real) {}

            public function open(StoreId $store, ReportKind $kind, array $entries): void
            {
                $this->real->open($store, $kind, $entries);
            }

            public function findOpen(int $entryId): ?array
            {
                return $this->real->findOpen($entryId);
            }

            public function closeOne(int $entryId, string $closedBy): bool
            {
                $this->real->closeOne($entryId, 'READ');

                return $this->real->closeOne($entryId, $closedBy);
            }

            public function pruneClosedBefore(DateTimeImmutable $before): int
            {
                return $this->real->pruneClosedBefore($before);
            }

            public function close(StoreId $store, ReportKind $kind, array $codes, array $variantIds, string $closedBy): int
            {
                return $this->real->close($store, $kind, $codes, $variantIds, $closedBy);
            }

            public function closeKind(StoreId $store, ReportKind $kind, string $closedBy): int
            {
                return $this->real->closeKind($store, $kind, $closedBy);
            }

            public function closeSwitchedOn(StoreId $store, array $variantIds): int
            {
                return $this->real->closeSwitchedOn($store, $variantIds);
            }

            public function closeAll(StoreId $store, string $closedBy): int
            {
                return $this->real->closeAll($store, $closedBy);
            }

            public function announce(StoreId $store, array $kinds): array
            {
                return $this->real->announce($store, $kinds);
            }

            public function hasOpen(StoreId $store, ReportKind $kind): bool
            {
                return $this->real->hasOpen($store, $kind);
            }
        });
        Fx::actAsStaff(Fx::staffWith([SyncPermissions::READ_RUN], ['sa']));

        expect(fn () => app(DismissEntryHandler::class)->handle(new DismissEntry($entry)))->toThrow(EntryNotFound::class)
            ->and(Fx::audits('sync.entry.dismissed', (string) $entry))->toBe(0);
    });

    it('never dismisses a failed read: it closes when a read works again (owner, 2026-10-18)', function () {
        DB::table('sync.report_entries')->insert(['store_id' => Fx::storeId('sa'), 'kind' => 'READ_FAILED', 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now()]);
        Fx::actAsStaff(Fx::staffWith([SyncPermissions::READ_RUN], ['sa']));

        expect(fn () => app(DismissEntryHandler::class)->handle(new DismissEntry((int) DB::table('sync.report_entries')->value('id'))))->toThrow(EntryNotDismissable::class)
            ->and(DB::table('sync.report_entries')->whereNull('closed_at')->count())->toBe(1);
    });

    it('tells staff a size deleted since, rather than reading anything', function () {
        ['sizes' => [, $second]] = syncReportRead();
        DB::table('sync.reads')->delete();
        $entry = (int) DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->value('id');
        DB::table('catalog.variants')->where('id', $second)->delete();
        Fx::actAsStaff(Fx::staffWith([SyncPermissions::READ_RUN], ['sa']));

        expect(fn () => app(RetryEntryHandler::class)->handle(new RetryEntry($entry)))->toThrow(SizeNotFound::class)
            ->and(DB::table('sync.reads')->count())->toBe(0);
    });
});

describe('an off or draft size switched on (§1.6)', function () {
    it('closes its entry once Catalog switches it on and its product is ready - and not before', function () {
        $ready = Px::ready(['60 cm']);
        $size = $ready['variants'][0];
        DB::table('sync.report_entries')->insert(['store_id' => Fx::storeId('sa'), 'kind' => 'OFF_OR_DRAFT_PRICED', 'variant_id' => $size, 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now()]);
        // Switched on while ready, then back to a draft: on, but not ready.
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
        DB::table('catalog.products')->where('id', $ready['product'])->update(['stage' => 'DRAFT']);
        DB::table('sync.report_entries')->update(['closed_at' => null, 'closed_by' => null]);
        event(new StoreListingChanged('e1', Fx::storeId('sa'), [$size], new DateTimeImmutable));

        expect(DB::table('sync.report_entries')->value('closed_by'))->toBeNull();

        DB::table('catalog.products')->where('id', $ready['product'])->update(['stage' => 'READY']);
        event(new ProductMadeReady('e2', $ready['product'], new DateTimeImmutable));

        expect(DB::table('sync.report_entries')->value('closed_by'))->toBe('CATALOG');
    });

    it('closes only that kind, and only for a size switched on in that store', function () {
        $on = Px::ready(['60 cm']);
        $off = Px::ready(['80 cm']);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $on['product'], true)));
        $entry = static fn (string $kind, string $size): array => ['store_id' => Fx::storeId('sa'), 'kind' => $kind, 'variant_id' => $size, 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now()];
        DB::table('sync.report_entries')->insert([$entry('OFF_OR_DRAFT_PRICED', $on['variants'][0]), $entry('BAD_VALUE', $on['variants'][0]), $entry('OFF_OR_DRAFT_PRICED', $off['variants'][0])]);

        event(new StoreListingChanged('e1', Fx::storeId('sa'), [$on['variants'][0], $off['variants'][0]], new DateTimeImmutable));

        expect(DB::table('sync.report_entries')->orderBy('id')->pluck('closed_by')->all())->toBe(['CATALOG', null, null]);
    });
});

describe('the log kept 90 days (§1.6)', function () {
    it('deletes reads finished, and entries closed, over 90 days ago - a read\'s lines with it; keeps the rest', function () {
        ['read' => $read] = syncReportRead();
        DB::table('sync.reads')->where('id', $read)->update(['finished_at' => now()->subDays(91)]);
        DB::table('sync.report_entries')->where('kind', 'UNKNOWN_CODE')->update(['closed_at' => now()->subDays(91), 'closed_by' => 'READ']);
        DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->update(['closed_at' => now()->subDays(89), 'closed_by' => 'READ']);
        $kept = DB::table('sync.reads')->insertGetId(['id' => $keptId = strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'kind' => 'HOURLY', 'state' => 'DONE', 'queued_at' => now()->subDays(89), 'started_at' => now()->subDays(89), 'finished_at' => now()->subDays(89)], 'id');

        $deleted = Fx::asSystem(fn (): array => app(PruneSyncLogHandler::class)->handle(new PruneSyncLog));

        expect($deleted)->toBe([1, 1])
            ->and(DB::table('sync.reads')->pluck('id')->all())->toBe([$keptId])
            ->and(DB::table('sync.read_lines')->count())->toBe(0)
            ->and(DB::table('sync.report_entries')->pluck('kind')->all())->toBe(['BAD_VALUE']);
    });

    it('is the system\'s, each night at 03:00 in Riyadh on one server', function () {
        Fx::actAsStaff(Fx::staff());

        expect(fn () => app(PruneSyncLogHandler::class)->handle(new PruneSyncLog))->toThrow(Unauthorized::class);

        $event = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event->description === PruneSyncLogJob::class);

        expect($event?->expression)->toBe('0 3 * * *')
            ->and($event?->timezone instanceof DateTimeZone ? $event->timezone->getName() : $event?->timezone)->toBe('Asia/Riyadh')
            ->and($event?->onOneServer)->toBeTrue();
    });
});
