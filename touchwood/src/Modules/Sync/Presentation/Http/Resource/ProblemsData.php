<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Problems (Y1): a page of open entries, the latest seen first, 50 at a time; where the next page starts;
 * how many the filter shows and how many are open in all; each kind's count.
 */
#[TypeScript]
final class ProblemsData extends Data
{
    /**
     * @param  list<ReportEntryData>  $entries
     * @param  list<KindCountData>  $kinds
     */
    public function __construct(
        public array $entries,
        public ?string $next,
        public int $total,
        public int $allOpen,
        public array $kinds,
    ) {}
}
