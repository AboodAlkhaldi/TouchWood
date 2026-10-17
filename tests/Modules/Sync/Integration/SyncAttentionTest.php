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
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Modules\Sync\Public\Events\SyncNeedsAttention;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| What staff are told of (sync.md §9.3 #18; access.md amendment 71): a sale stopped, a discount skipped,
| a read failed - each entry once, in one SyncNeedsAttention a read, when the read ends. Ops sends it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-18 10:00:00+00');
    Queue::fake();
    FakeProvider::bind();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'secret'));
    Event::fake([SyncNeedsAttention::class]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** The store's waiting read - one queued when none waits. */
function syncAttentionWaiting(): string
{
    Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Now));

    return (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');
}

/** The store's waiting read run to its end. */
function syncAttentionRead(): string
{
    $read = syncAttentionWaiting();
    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));

    return $read;
}

/** The store's waiting read's last attempt failed (sync.md §1.7). */
function syncAttentionFail(): string
{
    $read = syncAttentionWaiting();
    DB::table('sync.reads')->where('id', $read)->update(['state' => 'RUNNING', 'started_at' => now()]);
    Fx::asSystem(fn () => app(RunReadHandler::class)->failed($read, 'Timed out'));

    return $read;
}

/** An open entry of the kind, as a read leaves it (sizes and sales need not exist: no keys). */
function syncAttentionEntry(string $kind, ?string $store = null, bool $closed = false): void
{
    $id = static fn (): string => strtolower((string) Str::ulid());

    DB::table('sync.report_entries')->insert([
        'store_id' => $store ?? Fx::storeId('sa'), 'kind' => $kind, 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now(),
        'code' => $kind === 'UNKNOWN_CODE' ? $id() : null,
        'variant_id' => $kind === 'UNKNOWN_CODE' ? null : $id(),
        'sale_id' => $kind === 'SALE_STOPPED' ? $id() : null,
        'closed_at' => $closed ? now() : null, 'closed_by' => $closed ? 'READ' : null,
    ]);
}

it('tells of what a read opened that staff are told of, once, when it ends - by kind, and nothing else', function () {
    foreach (['SALE_STOPPED', 'SALE_STOPPED', 'DISCOUNT_SKIPPED', 'UNKNOWN_CODE', 'BAD_VALUE'] as $kind) {
        syncAttentionEntry($kind);
    }
    // Another store's, and one closed, are not this store's to tell.
    syncAttentionEntry('SALE_STOPPED', Fx::storeId('ae'));
    syncAttentionEntry('SALE_STOPPED', closed: true);
    FakeProvider::bound()->values = [new ProviderValue('987654', '5', 1)];

    $read = syncAttentionRead();

    Event::assertDispatchedTimes(SyncNeedsAttention::class, 1);
    Event::assertDispatched(SyncNeedsAttention::class, fn (SyncNeedsAttention $event): bool => $event->storeId === Fx::storeId('sa')
        && $event->readId === $read
        && $event->opened === ['DISCOUNT_SKIPPED' => 1, 'SALE_STOPPED' => 2]);

    // The next read sees the same: told already.
    syncAttentionRead();

    Event::assertDispatchedTimes(SyncNeedsAttention::class, 1);
});

it('tells of a read that failed - once, however often it fails again - and of one failing after a read worked again', function () {
    $first = syncAttentionFail();
    syncAttentionFail();

    Event::assertDispatchedTimes(SyncNeedsAttention::class, 1);
    Event::assertDispatched(SyncNeedsAttention::class, fn (SyncNeedsAttention $event): bool => $event->readId === $first && $event->opened === ['READ_FAILED' => 1]);

    // A read works: the failure closes. The next failure is a new one, told again.
    syncAttentionRead();
    syncAttentionFail();

    Event::assertDispatchedTimes(SyncNeedsAttention::class, 2);
});

it('tells again of another sale stopped, or another discount skipped, on a size already told of - never of the same one seen again', function () {
    $entries = app(ReportEntries::class);
    $store = StoreId::fromString(Fx::storeId('sa'));
    $size = strtolower((string) Str::ulid());
    [$first, $second] = [strtolower((string) Str::ulid()), strtolower((string) Str::ulid())];
    $sale = static fn (string $sale, string $outcome): array => [['code' => null, 'variantId' => $size, 'saleId' => $sale, 'detail' => ['outcome' => $outcome]]];
    $discount = static fn (string $discount): array => [['code' => null, 'variantId' => $size, 'saleId' => null, 'detail' => ['discount' => $discount]]];
    $told = static fn (): array => $entries->announce($store, [ReportKind::SaleStopped, ReportKind::DiscountSkipped, ReportKind::ReadFailed]);

    $entries->open($store, ReportKind::SaleStopped, $sale($first, 'left'));
    $entries->open($store, ReportKind::DiscountSkipped, $discount($first));
    $once = $told();
    $entries->open($store, ReportKind::SaleStopped, $sale($first, 'ended'));
    $entries->open($store, ReportKind::DiscountSkipped, $discount($first));
    $same = $told();
    $entries->open($store, ReportKind::SaleStopped, $sale($second, 'left'));
    $entries->open($store, ReportKind::DiscountSkipped, $discount($second));

    expect($once)->toBe(['DISCOUNT_SKIPPED' => 1, 'SALE_STOPPED' => 1])
        ->and($same)->toBe([])
        ->and($told())->toBe(['DISCOUNT_SKIPPED' => 1, 'SALE_STOPPED' => 1]);
});

it('never reports a read failed already - superseded or stopped - as the store failing', function () {
    $read = syncAttentionWaiting();
    DB::table('sync.reads')->where('id', $read)->update(['state' => 'FAILED', 'error' => 'Superseded: a read of the same kind waits.', 'finished_at' => now()]);

    Fx::asSystem(fn () => app(RunReadHandler::class)->failed($read, 'Timed out'));

    Event::assertNotDispatched(SyncNeedsAttention::class);
    expect(DB::table('sync.report_entries')->where('kind', 'READ_FAILED')->count())->toBe(0)
        ->and(DB::table('sync.reads')->where('id', $read)->value('error'))->toBe('Superseded: a read of the same kind waits.');
});

it('is a topic staff choose, in the panel by default (access.md amendment 71)', function () {
    $staff = Fx::staff();

    $preferences = [];

    foreach (app(AccessApi::class)->staffNotificationPreferences($staff) as $preference) {
        $preferences[$preference->topic->value] = [$preference->email, $preference->panel];
    }

    expect(StaffNotificationTopic::SyncProblems->value)->toBe('SYNC_PROBLEMS')
        ->and($preferences['SYNC_PROBLEMS'])->toBe([false, true])
        ->and(__('access::account.topic.SYNC_PROBLEMS', [], 'en'))->toBe('Sync Problems')
        ->and(__('access::account.topic.SYNC_PROBLEMS', [], 'ar'))->toBe('مشاكل المزامنة');
});
