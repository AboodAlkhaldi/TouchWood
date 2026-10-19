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
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sync\Application\Command\DismissEntry\DismissEntry;
use Modules\Sync\Application\Command\DismissEntry\DismissEntryHandler;
use Modules\Sync\Application\Command\RetryEntry\RetryEntry;
use Modules\Sync\Application\Command\RetryEntry\RetryEntryHandler;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\SyncNow\SyncNow;
use Modules\Sync\Application\Command\SyncNow\SyncNowHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Application\Connections\ConnectionStore;
use Modules\Sync\Application\Query\SyncStatus\SyncStatus;
use Modules\Sync\Application\Query\SyncStatus\SyncStatusHandler;
use Modules\Sync\Application\Query\ViewSyncRead\ViewSyncRead;
use Modules\Sync\Application\Query\ViewSyncRead\ViewSyncReadHandler;
use Modules\Sync\Application\Query\ViewSyncReport\ViewSyncReport;
use Modules\Sync\Application\Query\ViewSyncReport\ViewSyncReportHandler;
use Modules\Sync\Application\SyncPermissions as P;
use Modules\Sync\Domain\Exception\EntryNotRetryable;
use Modules\Sync\Domain\Exception\StoreOff;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| The independent review of stage 5's screens step 6, each point proven: an off store refused to whoever
| may not switch stores (Q1, `StoreOff`); Retry refused on what a read never closes (Q27,
| `EntryNotRetryable`); "size exists" said only of an entry about a size; a failed read's audit entry
| readable beside its code.
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
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** An open entry of this kind in the store, straight in; its id. */
function syncReviewEntry(string $store, string $kind, ?string $variantId = null, ?string $code = null): int
{
    return (int) DB::table('sync.report_entries')->insertGetId([
        'store_id' => Fx::storeId($store), 'kind' => $kind, 'code' => $code, 'variant_id' => $variantId,
        'sale_id' => $kind === 'SALE_STOPPED' ? strtolower((string) Str::ulid()) : null,
        'detail' => $kind === 'READ_FAILED' ? '{"error":"WORKER_GONE"}' : '{}', 'opened_at' => now(), 'seen_at' => now(),
    ]);
}

describe('an off store (Q1: StoreOff)', function () {
    it('refuses every read and action in it to someone who may not switch stores, and keeps it for a Super Admin', function () {
        app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('eg'), 'ODOO', 'https://odoo.test/api', 'key', 'secret'));
        $read = (string) DB::table('sync.reads')->where('store_id', Fx::storeId('eg'))->value('id');
        $entry = syncReviewEntry('eg', 'UNKNOWN_CODE', code: '987654');
        $reader = Fx::staffWith([P::REPORT_VIEW, P::READ_RUN], ['sa', 'eg']);
        // Its id while it is on: an off store's code finds no store.
        $eg = Fx::storeId('eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsStaff($reader);

        foreach ([
            'the report' => fn () => app(ViewSyncReportHandler::class)->handle(new ViewSyncReport($eg)),
            'the status' => fn () => app(SyncStatusHandler::class)->handle(new SyncStatus($eg)),
            'a read' => fn () => app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read)),
            'Sync now' => fn () => app(SyncNowHandler::class)->handle(new SyncNow($eg)),
            'Retry' => fn () => app(RetryEntryHandler::class)->handle(new RetryEntry($entry)),
            'Dismiss' => fn () => app(DismissEntryHandler::class)->handle(new DismissEntry($entry)),
        ] as $what => $work) {
            expect($work)->toThrow(StoreOff::class, null, $what);
        }

        // Its own store, on, as before.
        expect(app(SyncStatusHandler::class)->handle(new SyncStatus(Fx::storeId('sa')))->state)->toBe('NOT_WIRED');

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(SyncNowHandler::class)->handle(new SyncNow($eg));

        expect(app(ViewSyncReadHandler::class)->handle(new ViewSyncRead($read))->storeCode)->toBe('eg')
            ->and(DB::table('sync.reads')->where('store_id', $eg)->where('state', 'QUEUED')->count())->toBe(1);
    });

    it('refuses the Provider page\'s store when off to someone who may not switch stores', function () {
        $eg = Fx::storeId('eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        // Wiring is a Super Admin's, who may always switch stores: the refusal is proven on the check itself.
        $noSwitch = new ConnectionStore(new class implements Authorizer
        {
            public function authorize(string $permission, PermissionScope $scope): void {}

            public function storesWith(string $permission): array
            {
                return [];
            }

            public function isUnlimited(): bool
            {
                return false;
            }
        }, app(PlatformApi::class));

        expect(fn () => $noSwitch->of($eg))->toThrow(StoreOff::class)
            ->and($noSwitch->of(Fx::storeId('sa'))->value)->toBe(Fx::storeId('sa'))
            ->and(app(ConnectionStore::class)->of($eg)->value)->toBe($eg);
    });
});

describe('Retry on what a read never closes (Q27)', function () {
    it('refuses Retry on an Offer Stopped or an Off or Draft entry, queuing nothing', function () {
        app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'secret'));
        $ready = Px::ready(['60 cm']);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
        $stopped = syncReviewEntry('sa', 'SALE_STOPPED', $ready['variants'][0]);
        $priced = syncReviewEntry('sa', 'OFF_OR_DRAFT_PRICED', $ready['variants'][0]);
        $bad = syncReviewEntry('sa', 'BAD_VALUE', $ready['variants'][0]);
        DB::table('sync.reads')->delete();
        Fx::actAsStaff(Fx::staffWith([P::REPORT_VIEW, P::READ_RUN], ['sa']));

        expect(fn () => app(RetryEntryHandler::class)->handle(new RetryEntry($stopped)))->toThrow(EntryNotRetryable::class)
            ->and(fn () => app(RetryEntryHandler::class)->handle(new RetryEntry($priced)))->toThrow(EntryNotRetryable::class)
            ->and(DB::table('sync.reads')->count())->toBe(0);

        app(RetryEntryHandler::class)->handle(new RetryEntry($bad));

        expect(DB::table('sync.reads')->pluck('kind')->all())->toBe(['CODE']);
    });
});

describe('whether an entry\'s size exists', function () {
    it('says so only of an entry about a size: null for an unknown code or a failed read', function () {
        $ready = Px::ready(['60 cm']);
        syncReviewEntry('sa', 'UNKNOWN_CODE', code: '987654');
        syncReviewEntry('sa', 'READ_FAILED');
        syncReviewEntry('sa', 'BAD_VALUE', $ready['variants'][0]);
        syncReviewEntry('sa', 'NOT_IN_PROVIDER', strtolower((string) Str::ulid()));
        Fx::actAsStaff(Fx::staffWith([P::REPORT_VIEW], ['sa']));

        $exists = [];

        foreach (app(ViewSyncReportHandler::class)->handle(new ViewSyncReport(Fx::storeId('sa')))->entries as $entry) {
            $exists[$entry->about->kind] = $entry->about->sizeExists;
        }

        ksort($exists);

        expect($exists)->toBe(['BAD_VALUE' => true, 'NOT_IN_PROVIDER' => false, 'READ_FAILED' => null, 'UNKNOWN_CODE' => null]);
    });
});

describe('a failed read in the audit log (Q31)', function () {
    it('keeps Sync\'s code and its English sentence beside it; the provider\'s words as they came', function () {
        app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'secret'));
        $read = (string) DB::table('sync.reads')->value('id');
        app()->setLocale('ar');
        Fx::asSystem(fn () => app(RunReadHandler::class)->failed($read, 'JOB_FAILED'));
        $other = strtolower((string) Str::ulid());
        DB::table('sync.reads')->insert(['id' => $other, 'store_id' => Fx::storeId('sa'), 'kind' => 'HOURLY', 'state' => 'QUEUED', 'queued_at' => now()]);
        Fx::asSystem(fn () => app(RunReadHandler::class)->failed($other, 'Odoo answered 503.'));
        $changes = static fn (string $id): array => json_decode((string) DB::table('platform.audit_entries')->where('action', 'sync.read.failed')->where('subject_id', $id)->value('changes'), true);

        expect($changes($read))->toBe(['error' => [null, 'JOB_FAILED'], 'reason' => [null, 'Failed: see the failed jobs screen.']])
            ->and($changes($other))->toBe(['error' => [null, 'Odoo answered 503.']]);
    });
});
