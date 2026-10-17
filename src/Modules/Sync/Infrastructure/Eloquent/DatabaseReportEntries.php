<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sync\Domain\Repository\ReportEntries;
use Shared\Domain\ValueObject\StoreId;

/**
 * `sync.report_entries`.
 */
final readonly class DatabaseReportEntries implements ReportEntries
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function closeAll(StoreId $store, string $closedBy): int
    {
        return $this->db->table('sync.report_entries')
            ->where('store_id', $store->value)
            ->whereNull('closed_at')
            ->update(['closed_at' => CarbonImmutable::now(), 'closed_by' => $closedBy]);
    }
}
