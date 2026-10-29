<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Domain\Repository\OpsLog;

/** `OpsLog` in SQL (ops.md §5): each table's rows made before the moment go, on its age index. */
final readonly class DatabaseOpsLog implements OpsLog
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function prune(DateTimeImmutable $before): array
    {
        $at = Moment::of($before);

        return $this->db->transaction(fn (): array => [
            'inbox_entries' => $this->db->delete('DELETE FROM ops.inbox_entries WHERE created_at < ?::timestamptz', [$at]),
            'sent_messages' => $this->db->delete('DELETE FROM ops.sent_messages WHERE created_at < ?::timestamptz', [$at]),
            'handled_events' => $this->db->delete('DELETE FROM ops.handled_events WHERE handled_at < ?::timestamptz', [$at]),
        ]);
    }
}
