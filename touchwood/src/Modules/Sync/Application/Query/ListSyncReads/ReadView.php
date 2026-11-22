<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ListSyncReads;

use Modules\Sync\Application\Report\FailureView;

/**
 * One read as the page of reads shows it (sync.md §4.1 Y1 Reads, §9.3 #19): its kind and state; who asked,
 * by name, or "The System" (Q36) - a Super Admin read by anyone else as Platform names them; when it was
 * asked, started and finished, and how long it took; how many prices and stock numbers it changed and how
 * many it skipped - a failed read's counted from what it wrote (Q30); a notice's or a code's, how many
 * codes it asked for; a failed read's reason (Q31).
 */
final readonly class ReadView
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $state,
        public string $askedBy,
        public bool $askedBySystem,
        public ?int $codes,
        public int $pricesChanged,
        public int $stockChanged,
        public int $skipped,
        public ?FailureView $failure,
        public string $queuedAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?int $tookSeconds,
    ) {}
}
