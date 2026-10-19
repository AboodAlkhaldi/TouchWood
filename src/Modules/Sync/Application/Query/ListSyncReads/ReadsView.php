<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ListSyncReads;

/**
 * A page of a store's reads, the latest asked first: where the next page starts (null on the last), how
 * many the store keeps ("Showing 50 of 312"), and how many of its report's entries are open - the
 * Problems tab's count, drawn in its trigger while Reads is shown (§4.1 Y1, stage 5's screens step 7).
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
        public int $openEntries,
    ) {}
}
