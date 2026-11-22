<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Query\ApplicationCounts\ApplicationCounts;
use Modules\B2B\Public\Dto\ApplicationCountsDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * One round trip over the store's applications (b2b.md amendment 34): a send counts on the day it
 * was sent, an approval on the day it was decided. The moments go to PostgreSQL with their offset,
 * so the range is the same instant whatever zone the caller wrote it in.
 */
final readonly class DatabaseApplicationCounts implements ApplicationCounts
{
    private const string MOMENT = 'Y-m-d H:i:s.uP';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function in(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): ApplicationCountsDto
    {
        $start = $from->format(self::MOMENT);
        $end = $to->format(self::MOMENT);

        $row = $this->db->selectOne(<<<'SQL'
            SELECT
                count(*) FILTER (WHERE submitted_at >= ?::timestamptz AND submitted_at < ?::timestamptz) AS submitted,
                count(*) FILTER (WHERE state = 'APPROVED' AND decided_at >= ?::timestamptz AND decided_at < ?::timestamptz) AS approved
            FROM b2b.applications
            WHERE store_id = ?
            SQL, [$start, $end, $start, $end, $store->value]);

        return new ApplicationCountsDto((int) $row->submitted, (int) $row->approved);
    }
}
