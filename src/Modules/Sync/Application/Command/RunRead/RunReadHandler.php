<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\RunRead;

use Carbon\CarbonImmutable;
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
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Domain\ValueObject\ReportKind;
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
 * reported, and every entry a read finds right again closed. A failed attempt goes back to the queue
 * and is tried again (§1.7); `failed` is the last word. One audit entry per read.
 */
final readonly class RunReadHandler
{
    public const string PERMISSION = SyncPermissions::READ_SCHEDULED;

    /** Sizes a batch (handoff §12.2: never one at a time). */
    private const int BATCH = 500;

    /** A read running longer than this is taken as dead: its worker went away. */
    private const int STALE_MINUTES = 30;

    private const string CLOSED_BY_READ = 'READ';

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
        $this->reads->failStale($store, CarbonImmutable::now()->subMinutes(self::STALE_MINUTES)->toDateTimeImmutable());
        $read = $this->reads->claim($command->readId);

        // Not waiting any more, or another read of the store runs: that one starts this when it ends.
        if ($read === null) {
            return;
        }

        $connection = $this->connections->find($store);

        if ($connection === null) {
            $this->unwired($store, $read);

            return;
        }

        try {
            $counts = $this->run($store, $read, $connection);
        } catch (Throwable $error) {
            // Tried again by the queue (§1.7); nothing written so far is undone.
            $this->reads->requeue($read->id);

            throw $error;
        }

        if ($counts === null) {
            $this->unwired($store, $read);

            return;
        }

        [$prices, $stock, $skipped] = $counts;
        $this->reads->finish($read->id, $prices, $stock, $skipped);
        $this->connections->markRead($store, $read->isFull());
        $this->entries->closeKind($store, ReportKind::ReadFailed, self::CLOSED_BY_READ);
        $this->platform->recordAudit(new AuditEntryDto('sync.read.done', 'sync.read', $read->id, $store->value, AuditChanges::none()
            ->changed('kind', null, $read->kind->value)
            ->changed('prices_changed', null, $prices)
            ->changed('stock_changed', null, $stock)
            ->changed('skipped', null, $skipped)));
        $this->reads->startNext($store);
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

        if ($this->connections->exists($store)) {
            $this->connections->markFailing($store, $why);
            $this->entries->open($store, ReportKind::ReadFailed, null, null, null, ['read' => $readId, 'error' => mb_substr($why, 0, 1000)]);
        }

        $this->platform->recordAudit(new AuditEntryDto('sync.read.failed', 'sync.read', $readId, $store->value, AuditChanges::none()->changed('error', null, mb_substr($why, 0, 1000))));
        $this->reads->startNext($store);
    }

    private function unwired(StoreId $store, Read $read): void
    {
        $this->reads->fail($read->id, 'The store was unwired.');
        $this->reads->startNext($store);
    }

    /**
     * @return array{int, int, int}|null prices changed, stock changed, skipped; null when the store was unwired meanwhile
     */
    private function run(StoreId $store, Read $read, Connection $connection): ?array
    {
        $changedSince = $read->kind === ReadKind::Hourly ? $connection->lastReadAt : null;
        $values = $this->adapters->for($connection->provider)->read($connection, $read->codes, $changedSince);
        $counts = [0, 0, 0];
        $seen = [];
        $batch = [];

        foreach ($values as $value) {
            $batch[] = $value;

            if (count($batch) === self::BATCH) {
                if (! $this->batch($store, $read, $batch, $counts, $seen)) {
                    return null;
                }

                $batch = [];
            }
        }

        if ($batch !== [] && ! $this->batch($store, $read, $batch, $counts, $seen)) {
            return null;
        }

        // Only the night's full read finds our sizes the provider does not know (§1.3, §1.6).
        if ($read->kind === ReadKind::Nightly) {
            foreach (array_diff($this->catalog->switchedOnVariantIds($store), array_keys($seen)) as $variantId) {
                $this->entries->open($store, ReportKind::NotInProvider, null, $variantId, null, []);
            }
        }

        return $counts;
    }

    /**
     * One batch, written through Pricing's and Inventory's contracts (§1.4, §1.5); what cannot be
     * written reported, what is right again closed.
     *
     * @param  list<ProviderValue>  $values
     * @param  array{int, int, int}  $counts
     * @param  array<string, true>  $seen  sizes the provider sent
     */
    private function batch(StoreId $store, Read $read, array $values, array &$counts, array &$seen): bool
    {
        $variants = $this->catalog->variantsByCodes(array_map(static fn (ProviderValue $value): string => trim($value->code), $values));
        $codeOf = $priceIn = $stockIn = $bad = [];

        foreach ($values as $value) {
            $code = trim($value->code);
            $variant = $variants[$code] ?? null;

            // A code we do not have: reported, never created (§1.4 #1).
            if ($variant === null) {
                $this->entries->open($store, ReportKind::UnknownCode, $code, null, null, self::detail($value));
                $counts[2]++;

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

        $this->entries->close($store, ReportKind::UnknownCode, array_values($codeOf), [], self::CLOSED_BY_READ);
        $prices = $priceIn === [] ? null : $this->prices->apply($store, $priceIn, $read->id);
        $stock = $stockIn === [] ? null : $this->stock->apply($store, $stockIn, $read->id);

        if ($prices?->wired === false || $stock?->wired === false) {
            return false;
        }

        $archived = array_values(array_unique([...($prices->archived ?? []), ...($stock->archived ?? [])]));

        foreach ([...($prices->invalid ?? []), ...($stock->invalid ?? [])] as $variantId) {
            $bad[$variantId] ??= ['price' => $priceIn[$variantId] ?? null, 'stock' => $stockIn[$variantId] ?? null];
        }

        foreach ($archived as $variantId) {
            $this->entries->open($store, ReportKind::ArchivedSkipped, null, $variantId, null, ['code' => $codeOf[$variantId] ?? null]);
        }

        foreach ($bad as $variantId => $detail) {
            $this->entries->open($store, ReportKind::BadValue, null, $variantId, null, $detail);
        }

        foreach ($prices->offOrDraft ?? [] as $variantId) {
            $this->entries->open($store, ReportKind::OffOrDraftPriced, null, $variantId, null, ['price' => $priceIn[$variantId] ?? null]);
        }

        foreach ($prices->salesStopped ?? [] as $stopped) {
            $this->entries->open($store, ReportKind::SaleStopped, null, $stopped['variantId'], $stopped['saleId'], ['outcome' => $stopped['outcome']]);
        }

        $skippedNow = [];

        foreach ($prices->discountsSkipped ?? [] as $skipped) {
            $skippedNow[$skipped['variantId']] = true;
            $this->entries->open($store, ReportKind::DiscountSkipped, null, $skipped['variantId'], null, ['discount' => $skipped['discountId']]);
        }

        // Right again: a good value, a size restored, a discount reaching the size, a size found.
        $sizes = array_keys($codeOf);
        $this->entries->close($store, ReportKind::BadValue, [], array_values(array_diff($sizes, array_keys($bad))), self::CLOSED_BY_READ);
        $this->entries->close($store, ReportKind::ArchivedSkipped, [], array_values(array_diff($sizes, $archived)), self::CLOSED_BY_READ);
        $this->entries->close($store, ReportKind::DiscountSkipped, [], array_values(array_diff($prices->changed ?? [], array_keys($skippedNow))), self::CLOSED_BY_READ);
        $this->entries->close($store, ReportKind::NotInProvider, [], $sizes, self::CLOSED_BY_READ);

        $counts[0] += count($prices->changed ?? []);
        $counts[1] += count($stock->changed ?? []);
        $counts[2] += count($archived) + count($bad);

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
