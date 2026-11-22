<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Sync\Domain\Repository\ReadLines;

/**
 * `sync.read_lines`.
 */
final readonly class DatabaseReadLines implements ReadLines
{
    /** Rows a statement: 6 values each, well under PostgreSQL's bind limit. */
    private const int CHUNK = 1000;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(string $readId, string $kind, array $lines): void
    {
        foreach (array_chunk($lines, self::CHUNK) as $chunk) {
            $this->db->table('sync.read_lines')->insert(array_map(static fn (array $line): array => [
                'read_id' => $readId,
                'kind' => $kind,
                'variant_id' => $line['variantId'],
                'code' => $line['code'] === null ? null : mb_substr($line['code'], 0, 255),
                'before' => $line['before'] === null ? null : (string) $line['before'],
                'after' => (string) $line['after'],
            ], $chunk));
        }
    }

    public function count(string $readId, string $kind): int
    {
        return $this->db->table('sync.read_lines')->where('read_id', $readId)->where('kind', $kind)->count();
    }
}
