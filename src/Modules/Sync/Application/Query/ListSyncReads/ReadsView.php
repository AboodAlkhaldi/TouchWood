<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ListSyncReads;

/**
 * A page of a store's reads, the latest asked first, and how many there are.
 */
final readonly class ReadsView
{
    /**
     * @param  list<ReadView>  $reads
     */
    public function __construct(
        public array $reads,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
