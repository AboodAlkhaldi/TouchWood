<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Modules\Ops\Application\Reports\ReportKind;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The Reports page (ops.md §4.15 O8): the kind open, every kind this reader may open with its stores, the zone each
 * store's days are read in, the choices the page opens on, and - once the address names choices - the preview read
 * with them, or the refusal in words.
 */
#[TypeScript]
final class ReportsPageData extends Data
{
    /**
     * @param  list<ReportChoiceData>  $reports
     * @param  array<string, string>  $zones  store code (or `all`) => its time zone
     */
    public function __construct(
        public ReportKind $report,
        public array $reports,
        public array $zones,
        /** The choices the form opens on: the address's, or the store first on and this month to today. */
        public ReportAskedData $opening,
        /** The choices the address named, read below; null before Show Report. */
        public ?ReportAskedData $shown,
        public ?ReportPreviewData $preview,
        /** Why the choices shown were refused, in the page's words; null when they were not. */
        public ?string $refusal,
    ) {}
}
