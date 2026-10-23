<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\UnwireStore\UnwireStore;
use Modules\Sync\Application\Command\UnwireStore\UnwireStoreHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Application\Query\ListSyncReads\ListSyncReads;
use Modules\Sync\Application\Query\ListSyncReads\ListSyncReadsHandler;
use Modules\Sync\Application\Query\SyncActions\StoreSyncActions;
use Modules\Sync\Application\Query\SyncActions\SyncActionsForReader;
use Modules\Sync\Application\Query\SyncStatus\SyncStatus;
use Modules\Sync\Application\Query\SyncStatus\SyncStatusHandler;
use Modules\Sync\Application\Query\ViewConnection\ViewConnection;
use Modules\Sync\Application\Query\ViewConnection\ViewConnectionHandler;
use Modules\Sync\Application\Query\ViewSyncRead\ViewSyncRead;
use Modules\Sync\Application\Query\ViewSyncRead\ViewSyncReadHandler;
use Modules\Sync\Application\Query\ViewSyncReport\ViewSyncReport;
use Modules\Sync\Application\Query\ViewSyncReport\ViewSyncReportHandler;
use Modules\Sync\Application\Report\FailureView;
use Modules\Sync\Application\Report\ReportEntryView;
use Modules\Sync\Application\Report\SyncReportCount;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\ReadNotFound;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ProviderReason;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Infrastructure\Provider\OdooAdapter;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Sync's reads for stage 5's screens (sync.md §4.1.3; screens step 6): the status line with Not Wired, the
| report's Problems 50 at a time with the kind filter and their order, the reads 50 at a time, a read's
| page by section, the Provider page's connection (Until the Odoo connection is built, Q38), what the
| screens may offer, the menu's count - each a fixed few queries however many rows, counted warm - and
| Sync's own failure reasons kept as codes and written in the page's language (Q31).
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-19 10:00:00+00');
    Queue::fake();
    FakeProvider::bind();
    app()->setLocale('en');
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'secret'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function syncShownSa(): string
{
    return Fx::storeId('sa');
}

function syncShownCode(string $variantId): string
{
    return (string) DB::table('catalog.variants')->where('id', $variantId)->value('code');
}

/** The store's oldest waiting read, run as its job runs it; its id. */
function syncShownRun(): string
{
    $id = (string) DB::table('sync.reads')->where('state', 'QUEUED')->orderBy('queued_at')->orderBy('id')->value('id');
    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($id)));

    return $id;
}

/**
 * @param  list<string>|null  $codes
 */
function syncShownQueue(ReadKind $kind, ?array $codes = null): string
{
    return Fx::asSystem(fn (): string => app(Reads::class)->queue(StoreId::fromString(syncShownSa()), $kind, $codes));
}

/**
 * A ready product of these sizes sold in KSA.
 *
 * @param  list<string>  $sizes
 * @return array{product: string, variants: list<string>}
 */
function syncShownProduct(array $sizes = ['60 cm'], ?string $category = null): array
{
    $ready = Px::ready($sizes, $category);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(syncShownSa(), $ready['product'], true)));

    return $ready;
}

/**
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function syncShownReader(array $permissions = [SyncPermissions::REPORT_VIEW], array $stores = ['sa']): string
{
    $staff = Fx::staffWith($permissions, $stores);
    Fx::actAsStaff($staff);

    return $staff;
}

/** How many queries a read asks once warm - never the cache's. */
function syncShownQueries(callable $read): int
{
    $read();
    $queries = Cx::recordQueries();
    $read();

    return count($queries);
}

/**
 * Open entries written straight in, the latest seen last, as many as asked.
 */
function syncShownUnknownCodes(int $count): void
{
    DB::table('sync.report_entries')->insert(array_map(static fn (int $n): array => [
        'store_id' => syncShownSa(), 'kind' => 'UNKNOWN_CODE', 'code' => 'X'.$n, 'variant_id' => null, 'detail' => '{"price":"1","stock":1}',
        'opened_at' => now(), 'seen_at' => now()->addSeconds($n),
    ], range(1, $count)));
}

describe('the status line (S8, Q29, Q35)', function () {
    it('says the provider, Working, the newest finished read and the last full read, and a read waiting', function () {
        syncShownProduct();
        $first = syncShownRun();
        CarbonImmutable::setTestNow('2026-10-19 10:05:00+00');
        syncShownQueue(ReadKind::Code, ['123']);
        syncShownRun();
        CarbonImmutable::setTestNow('2026-10-19 10:10:00+00');
        syncShownQueue(ReadKind::Hourly);
        syncShownReader();

        $status = app(SyncStatusHandler::class)->handle(new SyncStatus(syncShownSa()));

        expect([$status->provider, $status->providerName, $status->state, $status->failure])->toBe(['ODOO', 'Odoo', 'WORKING', null])
            // Q29: the newest finished read of any kind - a one-code read's too - and the last full one's start.
            ->and($status->lastReadAt)->toBe('2026-10-19T10:05:00+00:00')
            ->and($status->lastFullReadAt)->toBe(CarbonImmutable::parse((string) DB::table('sync.reads')->where('id', $first)->value('started_at'))->format(DATE_ATOM))
            ->and($status->running)->toBeNull()
            ->and([$status->waiting?->kind, $status->waiting?->at])->toBe(['HOURLY', '2026-10-19T10:10:00+00:00']);

        DB::table('sync.reads')->where('kind', 'HOURLY')->update(['state' => 'RUNNING', 'started_at' => now()]);

        expect(app(SyncStatusHandler::class)->handle(new SyncStatus(syncShownSa()))->running?->kind)->toBe('HOURLY');
    });

    it('says Failing with Sync\'s own reason written in the page\'s language, or the provider\'s words as they came (Q31)', function () {
        syncShownReader();
        DB::table('sync.connections')->update(['state' => 'FAILING', 'last_error' => 'WORKER_GONE']);
        $own = app(SyncStatusHandler::class)->handle(new SyncStatus(syncShownSa()));
        app()->setLocale('ar');
        $arabic = app(SyncStatusHandler::class)->handle(new SyncStatus(syncShownSa()));
        DB::table('sync.connections')->update(['last_error' => 'Odoo answered 503.']);
        $provider = app(SyncStatusHandler::class)->handle(new SyncStatus(syncShownSa()));

        expect($own->state)->toBe('FAILING')
            ->and($own->failure)->toEqual(new FailureView('WORKER_GONE', 'Stopped: its worker went away.', null))
            ->and($arabic->failure?->text)->toBe('توقفت: توقف العامل الذي يشغّلها.')
            ->and($provider->failure)->toEqual(new FailureView(null, null, 'Odoo answered 503.'));
    });

    it('says the provider\'s reason kept as a code and its values in the page\'s language, and one stored before the codes as it is (stage 5 step 10)', function (string $locale, string $failed, string $late, string $unreached, string $pages) {
        syncShownReader();
        app()->setLocale($locale);
        $said = static function (string $stored): ?string {
            DB::table('sync.connections')->update(['state' => 'FAILING', 'last_error' => $stored]);

            return app(SyncStatusHandler::class)->handle(new SyncStatus(syncShownSa()))->failure?->providerSaid;
        };

        expect($said((new ProviderReason(ProviderReason::FAILED, ['status' => 503]))->stored()))->toBe($failed)
            ->and($said((new ProviderReason(ProviderReason::NO_ANSWER_WITHIN, ['seconds' => 30]))->stored()))->toBe($late)
            // 10 and 30 alike: the number never comes before a counted noun in Arabic (the review of step 10).
            ->and($said((new ProviderReason(ProviderReason::UNREACHABLE_WITHIN, ['seconds' => 10]))->stored()))->toBe($unreached)
            ->and($said((new ProviderReason(ProviderReason::TOO_MANY_PAGES, ['pages' => 200]))->stored()))->toBe($pages)
            ->and($said('The provider could not be read: Odoo failed (503).'))->toBe('The provider could not be read: Odoo failed (503).')
            // Not a reason of ours: a code unknown, or values it does not carry, shown as stored.
            ->and($said('{"code":"ODOO_FAILED","values":{}}'))->toBe('{"code":"ODOO_FAILED","values":{}}')
            ->and($said('{"code":"NOT_OURS","values":{}}'))->toBe('{"code":"NOT_OURS","values":{}}');
    })->with([
        'English' => ['en', 'Odoo failed (503).', 'Odoo did not answer within 30 seconds.', 'Odoo could not be reached within 10 seconds.', "Odoo's items run past 200 pages."],
        'Arabic' => ['ar', 'تعطّل Odoo (503).', 'لم يُجب Odoo خلال المهلة المحددة بالثواني (30).', 'تعذّر الوصول إلى Odoo خلال المهلة المحددة بالثواني (10).', 'تجاوزت عناصر Odoo الحدّ الأقصى للصفحات (200).'],
    ]);

    it('says Not Wired for a store with no connection, its reads still kept (Q35)', function () {
        syncShownRun();
        app(UnwireStoreHandler::class)->handle(new UnwireStore(syncShownSa()));
        syncShownReader();

        $status = app(SyncStatusHandler::class)->handle(new SyncStatus(syncShownSa()));

        expect([$status->provider, $status->state, $status->lastFullReadAt])->toBe([null, 'NOT_WIRED', null])
            ->and(app(ListSyncReadsHandler::class)->handle(new ListSyncReads(syncShownSa()))->total)->toBe(1);
    });

    it('is for the store\'s holders of View the Sync Report', function () {
        syncShownReader([SyncPermissions::REPORT_VIEW], ['ae']);

        expect(fn () => app(SyncStatusHandler::class)->handle(new SyncStatus(syncShownSa())))->toThrow(Unauthorized::class);
    });
});

describe('Sync\'s own failure reasons as codes (Q31)', function () {
    it('keeps every one of Sync\'s reasons as a code, each written in both languages', function () {
        foreach (['STOPPED_REWIRED', 'WORKER_GONE', 'UNWIRED', 'SUPERSEDED', 'JOB_FAILED'] as $code) {
            foreach (['en', 'ar'] as $locale) {
                $line = trans("sync::admin_report.failures.{$code}", [], $locale);

                expect(is_string($line) && $line !== '' && ! str_starts_with($line, 'sync::'))->toBeTrue("{$code} has no {$locale} words");
            }
        }

        // A store unwired drops its waiting read with its code, never a sentence.
        app(UnwireStoreHandler::class)->handle(new UnwireStore(syncShownSa()));

        expect(DB::table('sync.reads')->value('error'))->toBe('UNWIRED');
    });

    it('turns the sentences written before into their codes, and back', function () {
        $read = syncShownRun();
        DB::table('sync.reads')->where('id', $read)->update(['state' => 'FAILED', 'error' => 'Stopped: its worker went away.']);
        DB::table('sync.connections')->update(['state' => 'FAILING', 'last_error' => 'The read failed: see the failed jobs screen.']);
        DB::table('sync.report_entries')->insert(['store_id' => syncShownSa(), 'kind' => 'READ_FAILED', 'code' => null, 'variant_id' => null, 'detail' => json_encode(['read' => $read, 'error' => 'Superseded: a read of the same kind waits.']), 'opened_at' => now(), 'seen_at' => now()]);
        $migration = require base_path('src/Modules/Sync/Infrastructure/Persistence/Migrations/2026_10_19_100000_keep_sync_failures_as_codes.php');

        $migration->up();

        expect(DB::table('sync.reads')->where('id', $read)->value('error'))->toBe('WORKER_GONE')
            ->and(DB::table('sync.connections')->value('last_error'))->toBe('JOB_FAILED')
            ->and(json_decode((string) DB::table('sync.report_entries')->value('detail'), true)['error'])->toBe('SUPERSEDED');

        $migration->down();

        expect(DB::table('sync.reads')->where('id', $read)->value('error'))->toBe('Stopped: its worker went away.');
    });
});

describe('Problems (ViewSyncReport, Y1)', function () {
    it('pages 50 at a time, the latest seen first, after the last one shown, with the total and each kind\'s count', function () {
        syncShownUnknownCodes(120);
        syncShownReader();
        $read = static fn (?string $after = null, ?string $kind = null) => app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(syncShownSa(), $kind, $after));

        $first = $read();
        $second = $read($first->next);
        $third = $read($second->next);
        $codes = static fn ($page): array => array_map(static fn (ReportEntryView $entry): ?string => $entry->about->code, $page->entries);

        expect([count($first->entries), count($second->entries), count($third->entries)])->toBe([50, 50, 20])
            ->and($codes($first)[0])->toBe('X120')
            ->and($codes($second)[0])->toBe('X70')
            ->and($codes($third)[19])->toBe('X1')
            ->and($third->next)->toBeNull()
            ->and([$first->total, $first->allOpen, $first->byKind['UNKNOWN_CODE'], $first->byKind['BAD_VALUE']])->toBe([120, 120, 120, 0])
            ->and(array_keys($first->byKind))->toBe(['UNKNOWN_CODE', 'NOT_IN_PROVIDER', 'ARCHIVED_SKIPPED', 'OFF_OR_DRAFT_PRICED', 'SALE_STOPPED', 'BAD_VALUE', 'READ_FAILED', 'DISCOUNT_SKIPPED'])
            // The kind filter: its own total; every kind still counted.
            ->and([$read(null, 'bad_value')->total, $read(null, 'BAD_VALUE')->allOpen])->toBe([0, 120]);
    });

    it('refuses a kind or a place in the list that is not one', function () {
        syncShownReader();

        expect(fn () => app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(syncShownSa(), 'NOTHING')))->toThrow(InvalidSyncInput::class)
            ->and(fn () => app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(syncShownSa(), null, 'not-a-cursor')))->toThrow(InvalidSyncInput::class)
            ->and(fn () => app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(syncShownSa(), null, rtrim(base64_encode('2026-10-19 10:00:00+00|abc'), '='))))->toThrow(InvalidSyncInput::class);
    });

    it('names each size with its values, a stopped offer and a skipped discount with whether they still exist (Q37) - one deleted since among them -, and a failed read with its reason', function () {
        $category = Px::category('Kitchens');
        ['product' => $product, 'variants' => [$onSale, $discounted]] = syncShownProduct(['60 cm', '80 cm'], $category);
        DB::table('pricing.retail_prices')->insert(array_map(static fn (string $size): array => ['store_id' => syncShownSa(), 'variant_id' => $size, 'amount_minor' => 20000, 'currency_code' => 'SAR', 'source' => 'PROVIDER', 'updated_at' => now()], [$onSale, $discounted]));
        $sale = Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(syncShownSa(), null, [$onSale], null, '160', null, null)));
        $discount = Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(syncShownSa(), $category, null, '50', 'BOTH', null, null)));
        CarbonImmutable::setTestNow('2026-10-19 11:00:00+00');
        FakeProvider::bound()->values = [new ProviderValue(syncShownCode($onSale), '400', null), new ProviderValue(syncShownCode($discounted), '120', null)];
        $read = syncShownRun();
        // A stopped offer removed since, a skipped discount deleted since, and a failed read.
        DB::table('sync.report_entries')->insert([
            ['store_id' => syncShownSa(), 'kind' => 'DISCOUNT_SKIPPED', 'code' => null, 'variant_id' => $onSale, 'sale_id' => null, 'detail' => json_encode(['discount' => strtolower((string) Str::ulid())]), 'opened_at' => now(), 'seen_at' => now()->subMinutes(3)],
            ['store_id' => syncShownSa(), 'kind' => 'SALE_STOPPED', 'code' => null, 'variant_id' => $discounted, 'sale_id' => strtolower((string) Str::ulid()), 'detail' => '{"outcome":"removed"}', 'opened_at' => now(), 'seen_at' => now()->subMinute()],
            ['store_id' => syncShownSa(), 'kind' => 'READ_FAILED', 'code' => null, 'variant_id' => null, 'sale_id' => null, 'detail' => json_encode(['read' => $read, 'error' => 'SUPERSEDED']), 'opened_at' => now(), 'seen_at' => now()->subMinutes(2)],
        ]);
        syncShownReader();

        $about = [];

        foreach (app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(syncShownSa()))->entries as $entry) {
            $about[$entry->about->kind.($entry->about->offer?->exists === false || $entry->about->discount?->exists === false ? ' gone' : '')] = $entry->about;
        }

        expect(array_keys($about))->toEqualCanonicalizing(['SALE_STOPPED', 'DISCOUNT_SKIPPED', 'SALE_STOPPED gone', 'DISCOUNT_SKIPPED gone', 'READ_FAILED'])
            ->and([$about['SALE_STOPPED']->productId, $about['SALE_STOPPED']->values, $about['SALE_STOPPED']->code, $about['SALE_STOPPED']->sizeExists])->toBe([$product, ['60 cm'], syncShownCode($onSale), true])
            ->and([$about['SALE_STOPPED']->offer?->id, $about['SALE_STOPPED']->offer?->exists])->toBe([$sale, true])
            ->and($about['SALE_STOPPED']->offer?->description)->not->toBeNull()
            ->and([$about['DISCOUNT_SKIPPED']->discount?->id, $about['DISCOUNT_SKIPPED']->discount?->exists])->toBe([$discount, true])
            ->and($about['DISCOUNT_SKIPPED']->discount?->description)->toStartWith('Kitchens')
            ->and([$about['SALE_STOPPED gone']->offer?->exists, $about['SALE_STOPPED gone']->offer?->description])->toBe([false, null])
            // The page then says "Discount removed", with no link, as it says "Offer removed" (Q37).
            ->and([$about['DISCOUNT_SKIPPED gone']->discount?->exists, $about['DISCOUNT_SKIPPED gone']->discount?->description])->toBe([false, null])
            ->and([$about['READ_FAILED']->readId, $about['READ_FAILED']->failure?->code, $about['READ_FAILED']->failure?->text])->toBe([$read, 'SUPERSEDED', 'Superseded: a read of the same kind is waiting.']);
    });

    it('says a size deleted since no longer exists, keeping the code the provider sent', function () {
        DB::table('sync.report_entries')->insert(['store_id' => syncShownSa(), 'kind' => 'BAD_VALUE', 'code' => null, 'variant_id' => strtolower((string) Str::ulid()), 'detail' => '{"price":"0","stock":null}', 'opened_at' => now(), 'seen_at' => now()]);
        syncShownReader();

        $about = app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(syncShownSa()))->entries[0]->about;

        expect([$about->sizeExists, $about->productId, $about->values, $about->detail])->toBe([false, null, [], ['price' => '0', 'stock' => null]]);
    });
});

describe('Reads (ListSyncReads, Y1)', function () {
    it('pages 50 at a time, the latest asked first, after the last one shown, with the total', function () {
        DB::table('sync.reads')->delete();
        DB::table('sync.reads')->insert(array_map(static fn (int $n): array => [
            'id' => strtolower((string) Str::ulid()), 'store_id' => syncShownSa(), 'kind' => 'HOURLY', 'state' => 'DONE',
            'queued_at' => now()->addSeconds($n), 'started_at' => now()->addSeconds($n), 'finished_at' => now()->addSeconds($n + 134),
        ], range(1, 70)));
        syncShownReader();

        $first = app(ListSyncReadsHandler::class)->handle(new ListSyncReads(syncShownSa()));
        $second = app(ListSyncReadsHandler::class)->handle(new ListSyncReads(syncShownSa(), $first->next));

        expect([count($first->reads), count($second->reads), $first->total, $second->next])->toBe([50, 20, 70, null])
            ->and($first->reads[0]->queuedAt)->toBe('2026-10-19T10:01:10+00:00')
            ->and($second->reads[19]->queuedAt)->toBe('2026-10-19T10:00:01+00:00')
            ->and([$first->reads[0]->tookSeconds, $first->reads[0]->askedBy, $first->reads[0]->askedBySystem])->toBe([134, 'The System', true]);
    });

    it('names who asked (Q36), counts a failed read from what it wrote (Q30), and writes its reason', function () {
        ['variants' => [$size]] = syncShownProduct();
        FakeProvider::bound()->values = [new ProviderValue(syncShownCode($size), '120.50', 7), new ProviderValue('987654', '5', 1)];
        $read = syncShownRun();
        // Failed after writing: its own counts never written.
        DB::table('sync.reads')->where('id', $read)->update(['state' => 'FAILED', 'prices_changed' => 0, 'stock_changed' => 0, 'skipped' => 0, 'error' => 'Odoo answered 503.']);
        $asker = (string) DB::table('sync.reads')->where('id', $read)->value('requested_by_id');
        DB::table('access.staff_users')->where('id', $asker)->update(['first_name' => 'Huda']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $row = app(ListSyncReadsHandler::class)->handle(new ListSyncReads(syncShownSa()))->reads[0];

        expect([$row->pricesChanged, $row->stockChanged, $row->skipped])->toBe([1, 1, 1])
            ->and($row->askedBy)->toContain('Huda')
            ->and($row->askedBySystem)->toBeFalse()
            ->and($row->failure)->toEqual(new FailureView(null, null, 'Odoo answered 503.'));
    });

    it('refuses a place in the list that is not one', function () {
        syncShownReader();

        expect(fn () => app(ListSyncReadsHandler::class)->handle(new ListSyncReads(syncShownSa(), 'nope')))->toThrow(InvalidSyncInput::class);
    });
});

describe('a read\'s page (ViewSyncRead, Y2)', function () {
    it('pages each section on its own, 100 at a time, the codes asked among them (Q32)', function () {
        $codes = array_map(static fn (int $n): string => (string) (1000 + $n), range(1, 150));
        DB::table('sync.reads')->delete();
        $read = syncShownQueue(ReadKind::Notified, $codes);
        syncShownReader();

        $first = app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, 'codes'));
        $second = app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::CODES, 2));

        expect([$first->section, count($first->codes), $first->more, count($second->codes), $second->more])->toBe(['CODES', 100, true, 50, false])
            ->and($first->sections)->toBe(['prices' => 0, 'stock' => 0, 'reported' => 0, 'codes' => 150])
            ->and([$first->storeCode, $first->read->codes, $first->read->state, $first->read->tookSeconds])->toBe(['sa', 150, 'QUEUED', null])
            ->and(fn () => app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, 'NOTHING')))->toThrow(InvalidSyncInput::class);
    });

    it('is not found when pruned, another store\'s or never one (§9.3 #29)', function () {
        $read = syncShownRun();
        syncShownReader([SyncPermissions::REPORT_VIEW], ['ae']);

        expect(fn () => app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read)))->toThrow(ReadNotFound::class)
            ->and(fn () => app(ViewSyncReadHandler::class)->handle(new ViewSyncRead('nope')))->toThrow(ReadNotFound::class);

        syncShownReader();
        DB::table('sync.reads')->where('id', $read)->delete();

        expect(fn () => app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read)))->toThrow(ReadNotFound::class);
    });
});

describe('the Provider page (ViewConnection, Y3)', function () {
    it('says the Odoo connection is built (Q38: no "being built" note), with the notice address and what it takes (S7)', function () {
        app(UnwireStoreHandler::class)->handle(new UnwireStore(syncShownSa()));
        app()->forgetInstance(OdooAdapter::class);
        app()->offsetUnset(OdooAdapter::class);

        $view = app(ViewConnectionHandler::class)->handle(new ViewConnection(syncShownSa()));

        expect([$view->provider, $view->state, $view->providerReady, $view->providers, $view->keySet])->toBe([null, 'NOT_WIRED', true, ['ODOO'], false])
            ->and($view->noticeAddress)->toEndWith('/sync/notify/'.syncShownSa())
            ->and($view->noticeAddress)->toStartWith('https://')
            ->and($view->noticeMostCodes)->toBe(1000);
    });

    it('shows a wired store\'s state, its last reads and Failing\'s reason; never the key', function () {
        syncShownRun();
        DB::table('sync.connections')->update(['state' => 'FAILING', 'last_error' => 'JOB_FAILED']);

        $view = app(ViewConnectionHandler::class)->handle(new ViewConnection(syncShownSa()));

        expect([$view->provider, $view->providerName, $view->providerReady, $view->state, $view->failure?->code])->toBe(['ODOO', 'Odoo', true, 'FAILING', 'JOB_FAILED'])
            ->and($view->lastFullReadAt)->not->toBeNull()
            ->and($view->lastReadAt)->not->toBeNull()
            ->and(str_contains((string) json_encode($view), '"key"'))->toBeFalse();
    });
});

describe('what the screens may offer (SyncActionsForReader)', function () {
    it('answers per store: view, run, wire, the links, wired, a Sync Now read waiting (Q26)', function () {
        syncShownReader([SyncPermissions::REPORT_VIEW, SyncPermissions::READ_RUN, CatalogPermissions::PRODUCT_VIEW], ['sa']);
        $answer = app(SyncActionsForReader::class)->stores();

        expect($answer)->toEqual([new StoreSyncActions(syncShownSa(), true, true, false, true, false, true, false, true, 'A read is already waiting.')]);

        syncShownRun();
        syncShownReader([SyncPermissions::REPORT_VIEW, PricingPermissions::PRICE_EDIT], ['sa', 'ae']);

        expect(array_map(static fn (StoreSyncActions $store): array => [$store->storeId, $store->mayRun, $store->mayViewOffers, $store->wired, $store->nowWaiting], app(SyncActionsForReader::class)->stores()))
            ->toBe([[syncShownSa(), false, true, true, false], [Fx::storeId('ae'), false, true, false, false]])
            ->and(app(SyncActionsForReader::class)->reasons()->readFailedStays)->toBe('Closes by itself when a read works.');
    });

    it('offers a Super Admin every store to wire, the off ones too; someone else no off store (Q1)', function () {
        $reader = Fx::staffWith([SyncPermissions::REPORT_VIEW], ['sa', 'eg']);
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        $super = app(SyncActionsForReader::class)->stores();
        Fx::actAsStaff($reader);

        expect(count($super))->toBe((int) DB::table('platform.stores')->count())
            ->and(array_filter($super, static fn (StoreSyncActions $store): bool => ! $store->mayWire))->toBe([])
            ->and(array_map(static fn (StoreSyncActions $store): string => $store->storeId, app(SyncActionsForReader::class)->stores()))->toBe([syncShownSa()])
            ->and(app(SyncActionsForReader::class)->forStore(Fx::storeId('eg')))->toBeNull();
    });
});

describe('the menu\'s count (SyncReportCount)', function () {
    it('counts the open entries over the stores the reader views, an off one only for a Super Admin - in one query', function () {
        syncShownUnknownCodes(3);
        DB::table('sync.report_entries')->insert([
            ['store_id' => Fx::storeId('ae'), 'kind' => 'UNKNOWN_CODE', 'code' => 'A1', 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now(), 'closed_at' => null, 'closed_by' => null],
            ['store_id' => Fx::storeId('eg'), 'kind' => 'UNKNOWN_CODE', 'code' => 'E1', 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now(), 'closed_at' => null, 'closed_by' => null],
            ['store_id' => syncShownSa(), 'kind' => 'UNKNOWN_CODE', 'code' => 'C1', 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now(), 'closed_at' => now(), 'closed_by' => 'READ'],
        ]);
        $reader = Fx::staffWith([SyncPermissions::REPORT_VIEW], ['sa', 'eg']);
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

        expect(app(SyncReportCount::class)->count())->toBe(5);

        Fx::actAsStaff($reader);

        expect(app(SyncReportCount::class)->count())->toBe(3)
            ->and(syncShownQueries(static fn () => app(SyncReportCount::class)->count()))->toBe(1);
    });
});

describe('the budget (pricing.md §4.3.1: 15 queries at most a page, its own)', function () {
    it('reads each screen in a fixed few queries, the same for one row as for many', function () {
        ['variants' => $sizes] = syncShownProduct(array_map('strval', range(1, 12)));
        // Six sizes priced, six sent a price that is not one: lines and entries both.
        FakeProvider::bound()->values = [...array_map(static fn (string $size, int $n): ProviderValue => new ProviderValue(syncShownCode($size), $n < 6 ? '100' : '0', 3), $sizes, array_keys($sizes)), new ProviderValue('987654', '5', 1)];
        $read = syncShownRun();
        syncShownReader([SyncPermissions::REPORT_VIEW, SyncPermissions::READ_RUN, CatalogPermissions::PRODUCT_VIEW]);
        $sa = syncShownSa();
        $actions = static fn () => app(SyncActionsForReader::class)->stores();
        $status = static fn () => app(SyncStatusHandler::class)->handle(new SyncStatus($sa));

        $counts = [
            'problems' => syncShownQueries(static fn () => [$status(), $actions(), app(ViewSyncReportHandler::class)->handle(new ViewSyncReport($sa))]),
            'reads' => syncShownQueries(static fn () => [$status(), $actions(), app(ListSyncReadsHandler::class)->handle(new ListSyncReads($sa))]),
            'read prices' => syncShownQueries(static fn () => [$actions(), app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read))]),
            'read stock' => syncShownQueries(static fn () => [$actions(), app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::STOCK))]),
            'read reported' => syncShownQueries(static fn () => [$actions(), app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::REPORTED))]),
            'read codes' => syncShownQueries(static fn () => [$actions(), app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::CODES))]),
        ];
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $counts['provider'] = syncShownQueries(static fn () => [$actions(), app(ViewConnectionHandler::class)->handle(new ViewConnection($sa))]);
        $many = $counts['problems'];
        DB::table('sync.report_entries')->whereIn('id', DB::table('sync.report_entries')->where('kind', 'BAD_VALUE')->limit(5)->pluck('id')->all())->delete();
        syncShownReader([SyncPermissions::REPORT_VIEW, SyncPermissions::READ_RUN, CatalogPermissions::PRODUCT_VIEW]);

        expect(array_filter($counts, static fn (int $count): bool => $count > 15))->toBe([])
            // Measured, so a read that grows is seen.
            ->and($counts)->toBe(['problems' => 7, 'reads' => 6, 'read prices' => 8, 'read stock' => 8, 'read reported' => 8, 'read codes' => 4, 'provider' => 5])
            ->and(syncShownQueries(static fn () => [$status(), $actions(), app(ViewSyncReportHandler::class)->handle(new ViewSyncReport($sa))]))->toBe($many);
    });
});

describe('the budget\'s worst case (the review of step 6)', function () {
    it('reads Problems, Reads and a read\'s Reported within 15 with an offer stopped, a discount skipped, a bad value, an unknown code, a failed read and several askers', function () {
        $category = Px::category('Kitchens');
        ['variants' => [$onSale, $discounted, $bad]] = syncShownProduct(['60 cm', '80 cm', '100 cm'], $category);
        syncShownRun();
        DB::table('pricing.retail_prices')->insert(array_map(static fn (string $size): array => ['store_id' => syncShownSa(), 'variant_id' => $size, 'amount_minor' => 20000, 'currency_code' => 'SAR', 'source' => 'PROVIDER', 'updated_at' => now()], [$onSale, $discounted]));
        Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(syncShownSa(), null, [$onSale], null, '160', null, null)));
        Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(syncShownSa(), $category, null, '50', 'BOTH', null, null)));
        CarbonImmutable::setTestNow('2026-10-19 11:00:00+00');
        FakeProvider::bound()->values = [new ProviderValue(syncShownCode($onSale), '400', null), new ProviderValue(syncShownCode($discounted), '120', null), new ProviderValue(syncShownCode($bad), '0', 2), new ProviderValue('987654', '5', 1)];
        foreach ([Fx::staff(firstName: 'Huda'), Fx::staff(firstName: 'Omar'), Fx::staff(firstName: 'Lina')] as $asker) {
            // Asked by each of them, as Retry Read asks it.
            Fx::actAsStaff($asker);
            app(Reads::class)->queue(StoreId::fromString(syncShownSa()), ReadKind::Code, ['987654']);
            syncShownRun();
        }
        $read = syncShownQueue(ReadKind::Now);
        syncShownRun();
        DB::table('sync.report_entries')->insert(['store_id' => syncShownSa(), 'kind' => 'READ_FAILED', 'code' => null, 'variant_id' => null, 'sale_id' => null, 'detail' => json_encode(['read' => $read, 'error' => 'WORKER_GONE']), 'opened_at' => now(), 'seen_at' => now()]);
        syncShownReader([SyncPermissions::REPORT_VIEW, SyncPermissions::READ_RUN, CatalogPermissions::PRODUCT_VIEW]);
        $sa = syncShownSa();
        $actions = static fn () => app(SyncActionsForReader::class)->stores();
        $status = static fn () => app(SyncStatusHandler::class)->handle(new SyncStatus($sa));

        $counts = [
            'problems' => syncShownQueries(static fn () => [$status(), $actions(), app(ViewSyncReportHandler::class)->handle(new ViewSyncReport($sa))]),
            'reads' => syncShownQueries(static fn () => [$status(), $actions(), app(ListSyncReadsHandler::class)->handle(new ListSyncReads($sa))]),
            'read reported' => syncShownQueries(static fn () => [$actions(), app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::REPORTED))]),
        ];
        // The same reads by a Super Admin: every store, every link.
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $superCounts = [
            'problems' => syncShownQueries(static fn () => [$status(), $actions(), app(ViewSyncReportHandler::class)->handle(new ViewSyncReport($sa))]),
            'reads' => syncShownQueries(static fn () => [$status(), $actions(), app(ListSyncReadsHandler::class)->handle(new ListSyncReads($sa))]),
            'read reported' => syncShownQueries(static fn () => [$actions(), app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read, ViewSyncRead::REPORTED))]),
        ];

        expect($superCounts)->toBe(['problems' => 12, 'reads' => 6, 'read reported' => 12])
            ->and(DB::table('sync.report_entries')->whereNull('closed_at')->orderBy('kind')->pluck('kind')->all())->toBe(['BAD_VALUE', 'DISCOUNT_SKIPPED', 'READ_FAILED', 'SALE_STOPPED', 'UNKNOWN_CODE'])
            ->and(DB::table('sync.reads')->whereNotNull('requested_by_id')->distinct()->count('requested_by_id'))->toBe(4)
            ->and(array_filter($counts, static fn (int $count): bool => $count > 15))->toBe([])
            // Measured, so a read that grows is seen.
            ->and($counts)->toBe(['problems' => 12, 'reads' => 6, 'read reported' => 12]);
    });
});
