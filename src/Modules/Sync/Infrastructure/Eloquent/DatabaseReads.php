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
 * (`reads_one_running`, `reads_one_waiting`): two workers never both win.
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
            return (string) $this->db->table(self::TABLE)->where('store_id', $store->value)->where('kind', $kind->value)->where('state', 'QUEUED')->value('id');
        }

        ReadStoreJob::dispatch($id)->afterCommit();

        return $id;
    }

    public function dropQueued(StoreId $store, string $why): int
    {
        return $this->db->table(self::TABLE)
            ->where('store_id', $store->value)
            ->where('state', 'QUEUED')
            ->update(['state' => 'FAILED', 'error' => $why, 'finished_at' => CarbonImmutable::now()]);
    }

    public function claim(string $readId): ?Read
    {
        try {
            $rows = $this->db->transaction(fn (): array => $this->db->select(
                "UPDATE sync.reads SET state = 'RUNNING', started_at = now() WHERE id = ? AND state = 'QUEUED' RETURNING id, store_id, kind, codes",
                [$readId],
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

        return new Read((string) $row->id, (string) $row->store_id, ReadKind::from((string) $row->kind), $codes);
    }

    public function failStale(StoreId $store, DateTimeImmutable $runningSince): int
    {
        return $this->db->table(self::TABLE)
            ->where('store_id', $store->value)
            ->where('state', 'RUNNING')
            ->where('started_at', '<', $runningSince)
            ->update(['state' => 'FAILED', 'error' => 'Stopped: its worker went away.', 'finished_at' => CarbonImmutable::now()]);
    }

    public function requeue(string $readId): void
    {
        $this->db->table(self::TABLE)->where('id', $readId)->where('state', 'RUNNING')->update(['state' => 'QUEUED', 'started_at' => null]);
    }

    public function finish(string $readId, int $pricesChanged, int $stockChanged, int $skipped): void
    {
        $this->db->table(self::TABLE)->where('id', $readId)->update([
            'state' => 'DONE',
            'prices_changed' => $pricesChanged,
            'stock_changed' => $stockChanged,
            'skipped' => $skipped,
            'finished_at' => CarbonImmutable::now(),
        ]);
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

    public function storeOf(string $readId): ?string
    {
        $store = $this->db->table(self::TABLE)->where('id', $readId)->value('store_id');

        return $store === null ? null : (string) $store;
    }
}
