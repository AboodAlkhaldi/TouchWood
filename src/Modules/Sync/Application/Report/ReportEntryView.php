<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

/**
 * One open entry of the Sync report (sync.md §1.6, §4.1 Y1 Problems): what it is about, when it was
 * first seen and when last.
 */
final readonly class ReportEntryView
{
    public function __construct(
        public int $id,
        public ReportedView $about,
        public string $firstSeenAt,
        public string $lastSeenAt,
    ) {}
}
