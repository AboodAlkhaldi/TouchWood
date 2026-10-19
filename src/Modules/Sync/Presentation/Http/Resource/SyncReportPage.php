<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **The Sync Report** (sync.md §4.1 Y1): the frame, the status line, and the tab shown - Problems (a
 * wired store only, Q35) with its kind filter, or Reads.
 */
#[TypeScript]
final class SyncReportPage extends Data
{
    public function __construct(
        public SyncFrameData $storeFrame,
        public string $storeTimezone,
        public SyncStatusData $status,
        public string $tab,
        public ?string $kind,
        public ?ProblemsData $problems,
        public ?ReadsData $reads,
    ) {}
}
