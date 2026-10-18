<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Sync\Domain\Repository\ReadReports;
use Modules\Sync\Domain\ValueObject\ReportKind;

/**
 * `sync.read_reports`.
 */
final readonly class DatabaseReadReports implements ReadReports
{
    /** Rows a statement: 7 values each, well under PostgreSQL's bind limit. */
    private const int CHUNK = 1000;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(string $readId, ReportKind $kind, array $entries): void
    {
        foreach (array_chunk($entries, self::CHUNK) as $chunk) {
            $this->db->table('sync.read_reports')->insert(array_map(static fn (array $entry): array => [
                'read_id' => $readId,
                'kind' => $kind->value,
                'code' => $entry['code'] === null ? null : mb_substr($entry['code'], 0, 255),
                'variant_id' => $entry['variantId'],
                'sale_id' => $entry['saleId'],
                'detail' => json_encode((object) $entry['detail'], JSON_THROW_ON_ERROR),
            ], $chunk));
        }
    }
}
