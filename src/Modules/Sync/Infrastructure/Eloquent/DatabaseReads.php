<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Domain\ValueObject\StoreId;

/**
 * `sync.reads`. A read is asked by staff only (its CHECK, `reads_requested_by`); the system's are no one's.
 */
final readonly class DatabaseReads implements Reads
{
    private const string TABLE = 'sync.reads';

    public function __construct(
        private ConnectionInterface $db,
        private ActorContext $actors,
    ) {}

    public function queue(StoreId $store, ReadKind $kind): string
    {
        $actor = $this->actors->current();
        $staff = $actor->type === ActorType::Staff ? $actor : ($actor->requestedBy?->type === ActorType::Staff ? $actor->requestedBy : null);
        $id = strtolower((string) Str::ulid());

        $this->db->table(self::TABLE)->insert([
            'id' => $id,
            'store_id' => $store->value,
            'kind' => $kind->value,
            'requested_by_type' => $staff === null || in_array($kind, [ReadKind::Notified, ReadKind::Hourly], true) ? null : ActorType::Staff->value,
            'requested_by_id' => $staff === null || in_array($kind, [ReadKind::Notified, ReadKind::Hourly], true) ? null : $staff->id,
            'state' => 'QUEUED',
            'queued_at' => CarbonImmutable::now(),
        ]);

        return $id;
    }

    public function dropQueued(StoreId $store, string $why): int
    {
        $now = CarbonImmutable::now();

        return $this->db->table(self::TABLE)
            ->where('store_id', $store->value)
            ->where('state', 'QUEUED')
            ->update(['state' => 'FAILED', 'error' => $why, 'finished_at' => $now]);
    }
}
