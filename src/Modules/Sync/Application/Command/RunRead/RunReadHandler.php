<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\RunRead;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Pricing\Public\Contracts\ProviderPrices;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\Model\Read;
use Modules\Sync\Domain\Provider\ProviderAdapters;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\ReadLines;
use Modules\Sync\Domain\Repository\ReadReports;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Modules\Sync\Public\Events\SyncNeedsAttention;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * **One read of a store** (sync.md §1.3-§1.7), the system's (`sync.read.scheduled`): one per store at a
 * time - another waits for it - batched 500 sizes at a time, each batch written in Pricing's and
 * Inventory's own transactions, so a crash keeps what was written. The provider's codes matched to our
 * sizes; its prices and stock applied through their contracts; every code that cannot be written
 * reported, and every entry a read finds right again closed. Before each batch the read checks it still
 * runs for the store's present wiring: unwired, or wired again, it stops. A failed attempt goes back to
 * the queue and is tried again (§1.7); `failed` is the last word. One audit entry per read.
 */
final readonly class RunReadHandler
{
    public const string PERMISSION = SyncPermissions::READ_SCHEDULED;

    /** Sizes a batch (handoff §12.2: never one at a time). */
    private const int BATCH = 500;

    /** A read running longer than this is taken as dead: past its job's 20 minutes, its worker gone. */
    private const int STALE_MINUTES = 30;

    private const string CLOSED_BY_READ = 'READ';

    private const string STOPPED = 'Stopped: the store was unwired, or wired again, while it ran.';

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private Connections $connections,
        private Reads $reads,
        private ReportEntries $entries,
        private ProviderAdapters $adapters,
        private ProviderPrices $prices,
        private ProviderStock $stock,
        private ReadLines $lines,
        private ReadReports $reports,
        private Dispatcher $events,
    ) {}

    /**
     * @throws Unauthorized|ConnectionFailed
     */
    public function handle(RunRead $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $storeId = $this->reads->storeOf($command->readId);

        if ($storeId === null) {
            return;
        }

        $store = StoreId::fromString($storeId);
        $deadBefore = CarbonImmutable::now()->subMinutes(self::STALE_MINUTES)->toDateTimeImmutable();

        // Others of the store whose worker went away: failed the way a failed read is, so staff see it.
        foreach ($this->reads->failStale($store, $deadBefore, 'Stopped: its worker went away.', $command->readId) as $stale) {
            $this->report($store, $stale, 'Stopped: its worker went away.');
        }

        // Its own run, killed past its time, is taken back here and run again.
        $read = $this->reads->claim($command->readId, $deadBefore);

        // Not waiting any more, or another read of the store runs: that one starts this when it ends.
        if ($read === null) {
            return;
        }

        try {
            $this->finishRun($store, $read);
        } catch (Throwable $error) {
            // Tried again by the queue (§1.7); nothing written so far is undone.
            $this->reads->requeue($read->id);

            throw $error;
        }
    }

    /**
     * The read's last attempt failed (§1.7): the read failed, the store failing and reported, and the
     * next waiting read's turn.
     */
    public function failed(string $readId, string $why): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $storeId = $this->reads->storeOf($readId);

        if ($storeId === null) {
            return;
        }

        $store = StoreId::fromString($storeId);
        $this->reads->fail($readId, $why);
        $this->report($store, $readId, $why);
        $this->reads->startNext($store);
    }

    private function finishRun(StoreId $store, Read $read): void
    {
        $connection = $this->connections->find($store);
        $counts = $connection === null ? null : $this->run($store, $read, $connection);

        // Unwired before or while it ran - or taken over: whatever it wrote stays, the rest is not its.
        if ($counts === null || ! $this->reads->finish($read->id, ...$counts)) {
            $this->reads->fail($read->id, self::STOPPED);
            $this->platform->recordAudit(new AuditEntryDto('sync.read.failed', 'sync.read', $read->id, $store->value, AuditChanges::none()->changed('error', null, self::STOPPED)));
            $this->reads->startNext($store);

            return;
        }

        [$prices, $stock, $skipped] = $counts;
        $this->connections->markRead($store, $read->coversChanges() ? $read->startedAt : null, $read->isFull());
        $this->entries->closeKind($store, ReportKind::ReadFailed, self::CLOSED_BY_READ);
        $this->platform->recordAudit(new AuditEntryDto('sync.read.done', 'sync.read', $read->id, $store->value, AuditChanges::none()
            ->changed('kind', null, $read->kind->value)
            ->changed('prices_changed', null, $prices)
            ->changed('stock_changed', null, $stock)
            ->changed('skipped', null, $skipped)));
        $this->announce($store, $read->id);
        $this->reads->startNext($store);
    }

    /** A read failed: the store failing and reported - while still wired - and the failure audited. */
    private function report(StoreId $store, string $readId, string $why): void
    {
        $why = mb_substr($why, 0, 1000);

        if ($this->connections->exists($store)) {
            $this->connections->markFailing($store, $why);
            $this->reported($store, $readId, ReportKind::ReadFailed, [['code' => null, 'variantId' => null, 'saleId' => null, 'detail' => ['read' => $readId, 'error' => $why]]]);
            $this->announce($store, $readId);
        }

        $this->platform->recordAudit(new AuditEntryDto('sync.read.failed', 'sync.read', $readId, $store->value, AuditChanges::none()->changed('error', null, $why)));
    }

    /**
     * What staff are told of (sync.md §9.3 #18): a sale stopped, a discount skipped, a read failed -
     * each entry once, in one event a read, when the read ends. Ops sends it.
     */
    private function announce(StoreId $store, string $readId): void
    {
        $opened = $this->entries->announce($store, [ReportKind::SaleStopped, ReportKind::DiscountSkipped, ReportKind::ReadFailed]);

        if ($opened !== []) {
            $this->events->dispatch(new SyncNeedsAttention((string) Str::uuid(), $store->value, $readId, $opened, CarbonImmutable::now()));
        }
    }

    /**
     * Entries opened on the store's report, and the same written in the read's own record, as they
     * are today (owner, 2026-10-18: exact per read).
     *
     * @param  list<array{code: string|null, variantId: string|null, saleId: string|null, detail: array<string, mixed>}>  $entries
     */
    private function reported(StoreId $store, string $readId, ReportKind $kind, array $entries): void
    {
        $this->entries->open($store, $kind, $entries);
        $this->reports->add($readId, $kind, $entries);
    }

    /**
     * @return array{int, int, int}|null prices changed, stock changed, skipped; null when it stopped
     */
    private function run(StoreId $store, Read $read, Connection $connection): ?array
    {
        $wiring = $this->connections->wiredAt($store);
        $changedSince = $read->kind === ReadKind::Hourly ? $connection->lastReadAt : null;
        $values = $this->adapters->for($connection->provider)->read($connection, $read->codes, $changedSince);
        $counts = [0, 0, 0];
        $seen = [];
        $batch = [];

        foreach ($values as $value) {
            $batch[] = $value;

            if (count($batch) === self::BATCH) {
                if (! $this->current($store, $read, $wiring) || ! $this->batch($store, $read, $batch, $counts, $seen)) {
                    return null;
                }

                $batch = [];
            }
        }

        if ($batch !== [] && (! $this->current($store, $read, $wiring) || ! $this->batch($store, $read, $batch, $counts, $seen))) {
            return null;
        }

        // Only the night's full read finds our sizes the provider does not know (§1.3, §1.6).
        if ($read->kind === ReadKind::Nightly) {
            if (! $this->current($store, $read, $wiring)) {
                return null;
            }

            $this->reported($store, $read->id, ReportKind::NotInProvider, array_map(
                static fn (string $variantId): array => ['code' => null, 'variantId' => $variantId, 'saleId' => null, 'detail' => []],
                array_values(array_diff($this->catalog->switchedOnVariantIds($store), array_keys($seen))),
            ));
        }

        return $counts;
    }

    /** Whether the read still runs for the store's present wiring. */
    private function current(StoreId $store, Read $read, ?string $wiring): bool
    {
        return $wiring !== null && $this->reads->isRunning($read->id) && $this->connections->wiredAt($store) === $wiring;
    }

    /**
     * One batch, written through Pricing's and Inventory's contracts (§1.4, §1.5); what cannot be
     * written reported - only once they confirm the store is still wired - and what is right again
     * closed.
     *
     * @param  list<ProviderValue>  $values
     * @param  array{int, int, int}  $counts
     * @param  array<string, true>  $seen  sizes the provider sent
     */
    private function batch(StoreId $store, Read $read, array $values, array &$counts, array &$seen): bool
    {
        $variants = $this->catalog->variantsByCodes(array_map(static fn (ProviderValue $value): string => trim($value->code), $values));
        $codeOf = $priceIn = $stockIn = $bad = $unknown = [];

        foreach ($values as $value) {
            $code = trim($value->code);
            $variant = $variants[$code] ?? null;

            // A code we do not have: reported, never created (§1.4 #1).
            if ($variant === null) {
                $unknown[$code] = ['code' => $code, 'variantId' => null, 'saleId' => null, 'detail' => self::detail($value)];

                continue;
            }

            $codeOf[$variant->id] = $code;
            $seen[$variant->id] = true;

            if ($value->price !== null) {
                // The provider's text as it came; a number written as one (sync.md §1.4).
                $priceIn[$variant->id] = is_int($value->price) || is_float($value->price) ? (string) $value->price : $value->price;
            }

            if ($value->stock !== null) {
                $number = self::whole($value->stock);

                // Not a whole number: skipped; below 0: kept as 0. Both reported (§1.5 #2, §9.2 #11).
                if ($number === null || $number < 0) {
                    $bad[$variant->id] = self::detail($value);
                }

                if ($number !== null) {
                    $stockIn[$variant->id] = max(0, $number);
                }
            }
        }

        $prices = $priceIn === [] ? null : $this->prices->apply($store, $priceIn, $read->id);
        $stock = $stockIn === [] ? null : $this->stock->apply($store, $stockIn, $read->id);

        if ($prices?->wired === false || $stock?->wired === false) {
            return false;
        }

        $archived = array_values(array_unique([...($prices->archived ?? []), ...($stock->archived ?? [])]));

        foreach ([...($prices->invalid ?? []), ...($stock->invalid ?? [])] as $variantId) {
            $bad[$variantId] ??= ['price' => $priceIn[$variantId] ?? null, 'stock' => $stockIn[$variantId] ?? null];
        }

        // Sizes a contract took and checked: archived or not is known only for them.
        $checked = array_values(array_unique([
            ...array_diff(array_keys($priceIn), $prices->invalid ?? [], $prices->notFound ?? []),
            ...array_diff(array_keys($stockIn), $stock->invalid ?? [], $stock->notFound ?? []),
        ]));
        $sizes = array_keys($codeOf);

        $this->reported($store, $read->id, ReportKind::UnknownCode, array_values($unknown));
        $this->entries->close($store, ReportKind::UnknownCode, array_values($codeOf), [], self::CLOSED_BY_READ);
        $this->reported($store, $read->id, ReportKind::ArchivedSkipped, array_map(static fn (string $variantId): array => ['code' => null, 'variantId' => $variantId, 'saleId' => null, 'detail' => ['code' => $codeOf[$variantId] ?? null]], $archived));
        $this->reported($store, $read->id, ReportKind::BadValue, array_map(static fn (string $variantId, array $detail): array => ['code' => null, 'variantId' => $variantId, 'saleId' => null, 'detail' => $detail], array_keys($bad), $bad));
        $this->reported($store, $read->id, ReportKind::OffOrDraftPriced, array_map(static fn (string $variantId): array => ['code' => null, 'variantId' => $variantId, 'saleId' => null, 'detail' => ['price' => $priceIn[$variantId] ?? null]], $prices->offOrDraft ?? []));
        $this->reported($store, $read->id, ReportKind::SaleStopped, array_map(static fn (array $stopped): array => ['code' => null, 'variantId' => $stopped['variantId'], 'saleId' => $stopped['saleId'], 'detail' => ['outcome' => $stopped['outcome']]], $prices->salesStopped ?? []));
        $this->reported($store, $read->id, ReportKind::DiscountSkipped, array_map(static fn (array $skipped): array => ['code' => null, 'variantId' => $skipped['variantId'], 'saleId' => null, 'detail' => ['discount' => $skipped['discountId']]], $prices->discountsSkipped ?? []));

        // The read's lines (§9.3 #19): each price and stock number it changed, from and to.
        $this->lines->add($read->id, 'PRICE', array_map(static fn (array $change): array => [...$change, 'code' => $codeOf[$change['variantId']] ?? null], $prices->changes ?? []));
        $this->lines->add($read->id, 'STOCK', array_map(static fn (array $change): array => [...$change, 'code' => $codeOf[$change['variantId']] ?? null], $stock->changes ?? []));

        // Right again: a good value, a size checked and not archived, a discount reaching it, a size found.
        $skippedNow = array_column($prices->discountsSkipped ?? [], 'variantId');
        $this->entries->close($store, ReportKind::BadValue, [], array_values(array_diff($sizes, array_keys($bad))), self::CLOSED_BY_READ);
        $this->entries->close($store, ReportKind::ArchivedSkipped, [], array_values(array_diff($checked, $archived)), self::CLOSED_BY_READ);
        $this->entries->close($store, ReportKind::DiscountSkipped, [], array_values(array_diff($prices->changed ?? [], $skippedNow)), self::CLOSED_BY_READ);
        $this->entries->close($store, ReportKind::NotInProvider, [], $sizes, self::CLOSED_BY_READ);

        $counts[0] += count($prices->changed ?? []);
        $counts[1] += count($stock->changed ?? []);
        $counts[2] += count($unknown) + count(array_unique([...$archived, ...array_keys($bad)]));

        return true;
    }

    /** A whole number as the provider may write it - 5, 5.0, "5" - or null when it is not one. */
    private static function whole(mixed $stock): ?int
    {
        return match (true) {
            is_int($stock) => $stock,
            is_float($stock) && is_finite($stock) && floor($stock) === $stock && abs($stock) < PHP_INT_MAX => (int) $stock,
            is_string($stock) && preg_match('/\A\s*-?\d{1,18}\s*\z/', $stock) === 1 => (int) trim($stock),
            default => null,
        };
    }

    /**
     * What the provider sent for a code, as the report shows it.
     *
     * @return array<string, mixed>
     */
    private static function detail(ProviderValue $value): array
    {
        $plain = static fn (mixed $given): string|int|float|bool|null => is_scalar($given) || $given === null ? $given : get_debug_type($given);

        return ['price' => $plain($value->price), 'stock' => $plain($value->stock)];
    }
}
