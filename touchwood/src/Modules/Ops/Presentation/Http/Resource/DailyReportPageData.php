<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A page of the daily reports kept (ops.md §1.11, §4), newest first, and the next page's cursor. */
#[TypeScript]
final class DailyReportPageData extends Data
{
    /**
     * @param  list<DailyReportData>  $reports
     */
    public function __construct(
        public array $reports,
        public ?string $next,
    ) {}
}
