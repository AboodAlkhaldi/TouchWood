<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * E7 — the failed jobs, oldest first, 50 at a time (frontend.md 3.5).
 */
#[TypeScript]
final class FailedJobsPage extends Data
{
    /**
     * @param  list<FailedJobRowData>  $jobs
     */
    public function __construct(
        public array $jobs,
        /** Where "Show more" starts — the last job's time and id — or null when this is the last page. */
        public ?string $nextFailedAt,
        public ?string $nextId,
    ) {}
}
