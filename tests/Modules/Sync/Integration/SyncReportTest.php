<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
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
use Modules\Sync\Domain\Exception\EntryNotFound;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\NotWired;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Shared\Application\Unauthorized;
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

        $view = app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read));

        expect(array_map(static fn ($line): array => [$line->variantId, $line->productId, $line->before, $line->after], $view->prices))->toBe([[$first, $product, null, '120.50']])
            ->and(array_map(static fn ($line): array => [$line->variantId, $line->before, $line->after], $view->stock))->toBe([[$first, '0', '7']])
            ->and($view->prices[0]->productNameEn)->not->toBeNull()
            ->and(array_map(static fn ($entry): string => $entry->kind.' '.($entry->productId ?? $entry->code), $view->reported))->toEqualCanonicalizing(["BAD_VALUE {$product}", 'UNKNOWN_CODE 987654']);
    });

    it('shows a read to whoever may see its store\'s report, and to no one else', function () {
        ['read' => $read] = syncReportRead();
        Fx::actAsStaff(Fx::staffWith([SyncPermissions::REPORT_VIEW], ['ae']));

        expect(fn () => app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read)))->toThrow(Unauthorized::class)
            ->and(fn () => app(ViewSyncReadHandler::class)->handle(new ViewSyncRead('01jbzzzzzzzzzzzzzzzzzzzzzz')))->toThrow(EntryNotFound::class);
    });
});

describe('the report (§1.6)', function () {
    it('shows the open entries, the latest seen first, naming each size\'s product or the provider\'s code - one kind or all - and counts them', function () {
        ['product' => $product] = syncReportRead();
        Fx::actAsStaff(syncReportViewer());

        $all = app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(Fx::storeId('sa')));
        $unknown = app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(Fx::storeId('sa'), 'UNKNOWN_CODE'));

        expect($all->total)->toBe(2)
            ->and(array_map(static fn ($entry): string => $entry->kind.' '.($entry->productId ?? $entry->code), $all->entries))->toEqualCanonicalizing(["BAD_VALUE {$product}", 'UNKNOWN_CODE 987654'])
            ->and(array_map(static fn ($entry): string => (string) $entry->code, $unknown->entries))->toBe(['987654'])
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

    it('refuses an entry\'s action to whoever may not run the store\'s reads', function () {
        syncReportRead();
        Fx::actAsStaff(syncReportViewer());
        $entry = (int) DB::table('sync.report_entries')->value('id');

        expect(fn () => app(DismissEntryHandler::class)->handle(new DismissEntry($entry)))->toThrow(Unauthorized::class)
            ->and(fn () => app(RetryEntryHandler::class)->handle(new RetryEntry($entry)))->toThrow(Unauthorized::class);
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

    it('is the system\'s', function () {
        Fx::actAsStaff(Fx::staff());

        expect(fn () => app(PruneSyncLogHandler::class)->handle(new PruneSyncLog))->toThrow(Unauthorized::class);
    });
});
