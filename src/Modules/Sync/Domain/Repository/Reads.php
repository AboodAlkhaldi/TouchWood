<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Repository;

use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * The log of each store's reads (sync.md §5 `sync.reads`).
 */
interface Reads
{
    /**
     * A read waiting its turn, asked by whoever acts now when staff do (sync.md §1.3).
     *
     * @return string its id
     */
    public function queue(StoreId $store, ReadKind $kind): string;

    /** The store's waiting reads dropped - it was unwired (sync.md §1.8): failed, saying why. */
    public function dropQueued(StoreId $store, string $why): int;
}
