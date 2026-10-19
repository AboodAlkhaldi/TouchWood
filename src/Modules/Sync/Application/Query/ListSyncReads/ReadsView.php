<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ListSyncReads;

/**
 * A page of a store's reads, the latest asked first: where the next page starts (null on the last) and
 * how many the store keeps ("Showing 50 of 312").
 */
final readonly class ReadsView
{
    /**
     * @param  list<ReadView>  $reads
     */
    public function __construct(
        public array $reads,
        public ?string $next,
        public int $total,
    ) {}
}
