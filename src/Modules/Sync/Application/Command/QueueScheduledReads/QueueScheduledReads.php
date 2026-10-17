<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\QueueScheduledReads;

/**
 * The hour's or the night's reads queued for every wired store (sync.md §1.3).
 */
final readonly class QueueScheduledReads
{
    /**
     * @param  'HOURLY'|'NIGHTLY'  $kind
     */
    public function __construct(
        public string $kind,
    ) {}
}
