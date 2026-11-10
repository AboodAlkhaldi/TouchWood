<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Repository\ContentLocks;

/** `ContentLocks` as PostgreSQL advisory locks, held until the transaction ends (`pg_advisory_xact_lock`). */
final readonly class DatabaseContentLocks implements ContentLocks
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function lock(string $storeId, string $topic): void
    {
        $this->db->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['content:'.$topic.':'.strtolower($storeId)]);
    }
}
