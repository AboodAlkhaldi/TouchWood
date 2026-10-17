<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Sync\Domain\Model\Read;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Infrastructure\Queue\ReadStoreJob;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * `sync.reads`. A read is asked by staff only (its CHECK, `reads_requested_by`); the system's are no
 * one's. One running per store, and one full read of each kind waiting, are the table's unique indexes
 * (`reads_one_running`, `reads_one_waiting`): two workers never both win. Every time written is the
 * app's clock, so the log reads in one order.
 */
final readonly class DatabaseReads implements Reads
{
    private const string TABLE = 'sync.reads';

    public function __construct(
        private ConnectionInterface $db,
        private ActorContext $actors,
    ) {}

    public function queue(StoreId $store, ReadKind $kind, ?array $codes = null): string
    {
        $actor = $this->actors->current();
        $staff = $actor->type === ActorType::Staff ? $actor : ($actor->requestedBy?->type === ActorType::Staff ? $actor->requestedBy : null);
        $asked = $staff !== null && ! in_array($kind, [ReadKind::Notified, ReadKind::Hourly], true);

        // Twice at most: a waiting read claimed between the refused insert and the look-up leaves room.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $id = strtolower((string) Str::ulid());

            try {
                // A savepoint of its own: a full read already waiting must not abort the caller's work.
                $this->db->transaction(fn () => $this->db->table(self::TABLE)->insert([
                    'id' => $id,
                    'store_id' => $store->value,
                    'kind' => $kind->value,
                    'codes' => $codes === null ? null : json_encode($codes, JSON_THROW_ON_ERROR),
                    'requested_by_type' => $asked ? ActorType::Staff->value : null,
                    'requested_by_id' => $asked ? $staff->id : null,
                    'state' => 'QUEUED',
                    'queued_at' => CarbonImmutable::now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                // One waiting read of each kind (§1.3): a notice's or a code's takes the new codes too.
                $waiting = $codes === null
                    ? $this->db->table(self::TABLE)->where('store_id', $store->value)->where('kind', $kind->value)->where('state', 'QUEUED')->value('id')
                    : ($this->db->select(
                        'UPDATE sync.reads SET codes = (SELECT jsonb_agg(DISTINCT code ORDER BY code) FROM jsonb_array_elements_text(codes || ?::jsonb) AS code)'
                        ." WHERE store_id = ? AND kind = ? AND state = 'QUEUED' RETURNING id",
                        [json_encode($codes, JSON_THROW_ON_ERROR), $store->value, $kind->value],
                    )[0]->id ?? null);

                if ($waiting === null) {
                    continue;
                }

                // Its job may be gone (its claim refused while another ran): queued again, harmless.
                $id = (string) $waiting;
            }

            ReadStoreJob::dispatch($id)->afterCommit();

            return $id;
        }

        throw new \RuntimeException('A read could not be queued.');
    }

    public function dropUnfinished(StoreId $store, string $why): int
    {
        return $this->db->table(self::TABLE)
            ->where('store_id', $store->value)
            ->whereIn('state', ['QUEUED', 'RUNNING'])
            ->update(['state' => 'FAILED', 'error' => $why, 'finished_at' => CarbonImmutable::now()]);
    }

    public function claim(string $readId, DateTimeImmutable $deadBefore): ?Read
    {
        try {
            $rows = $this->db->transaction(fn (): array => $this->db->select(
                "UPDATE sync.reads SET state = 'RUNNING', started_at = ?"
                ." WHERE id = ? AND (state = 'QUEUED' OR (state = 'RUNNING' AND started_at < ?))"
                .' RETURNING id, store_id, kind, codes, started_at',
                [CarbonImmutable::now(), $readId, $deadBefore],
            ));
        } catch (UniqueConstraintViolationException) {
            // Another read of the store runs: this one waits its turn (startNext).
            return null;
        }

        $row = $rows[0] ?? null;

        if (! $row instanceof stdClass) {
            return null;
        }

        /** @var list<string>|null $codes */
        $codes = $row->codes === null ? null : json_decode((string) $row->codes, true, flags: JSON_THROW_ON_ERROR);

        return new Read((string) $row->id, (string) $row->store_id, ReadKind::from((string) $row->kind), $codes, CarbonImmutable::parse((string) $row->started_at)->toDateTimeImmutable());
    }

    public function failStale(StoreId $store, DateTimeImmutable $runningSince, string $why, string $except): array
    {
        $rows = $this->db->select(
            "UPDATE sync.reads SET state = 'FAILED', error = ?, finished_at = ? WHERE store_id = ? AND state = 'RUNNING' AND started_at < ? AND id <> ? RETURNING id",
            [$why, CarbonImmutable::now(), $store->value, $runningSince, $except],
        );

        return array_values(array_map(static fn (stdClass $row): string => (string) $row->id, $rows));
    }

    public function isRunning(string $readId): bool
    {
        return $this->db->table(self::TABLE)->where('id', $readId)->where('state', 'RUNNING')->exists();
    }

    public function requeue(string $readId): void
    {
        try {
            $this->db->transaction(fn () => $this->db->table(self::TABLE)->where('id', $readId)->where('state', 'RUNNING')->update(['state' => 'QUEUED', 'started_at' => null]));
        } catch (UniqueConstraintViolationException) {
            // A read of the same kind waits already: it covers this one, which is superseded.
            $this->fail($readId, 'Superseded: a read of the same kind waits.');
            $store = $this->storeOf($readId);

            if ($store !== null) {
                $this->startNext(StoreId::fromString($store));
            }
        }
    }

    public function finish(string $readId, int $pricesChanged, int $stockChanged, int $skipped): bool
    {
        return $this->db->table(self::TABLE)->where('id', $readId)->where('state', 'RUNNING')->update([
            'state' => 'DONE',
            'prices_changed' => $pricesChanged,
            'stock_changed' => $stockChanged,
            'skipped' => $skipped,
            'finished_at' => CarbonImmutable::now(),
        ]) === 1;
    }

    public function fail(string $readId, string $why): void
    {
        $this->db->table(self::TABLE)->where('id', $readId)->whereIn('state', ['QUEUED', 'RUNNING'])->update([
            'state' => 'FAILED',
            'error' => mb_substr($why, 0, 1000),
            'finished_at' => CarbonImmutable::now(),
        ]);
    }

    public function startNext(StoreId $store): void
    {
        $next = $this->db->table(self::TABLE)->where('store_id', $store->value)->where('state', 'QUEUED')->orderBy('queued_at')->orderBy('id')->value('id');

        if ($next !== null) {
            ReadStoreJob::dispatch((string) $next)->afterCommit();
        }
    }

    public function pruneFinishedBefore(DateTimeImmutable $before): int
    {
        return $this->db->table(self::TABLE)->whereIn('state', ['DONE', 'FAILED'])->where('finished_at', '<', $before)->delete();
    }

    public function storeOf(string $readId): ?string
    {
        $store = $this->db->table(self::TABLE)->where('id', $readId)->value('store_id');

        return $store === null ? null : (string) $store;
    }
}
