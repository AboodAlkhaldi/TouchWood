<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The reports page's preview (ops.md §1.10, §4): the report in the reader's language - right to left in
 * Arabic - its title and the line naming its store and days, each sheet's first rows, and the Excel file's
 * name and address (the same choices, `/download`).
 */
#[TypeScript]
final class ReportPreviewData extends Data
{
    /**
     * @param  list<ReportSheetData>  $sheets
     */
    public function __construct(
        public string $report,
        public string $locale,
        public bool $rightToLeft,
        public string $title,
        public string $subtitle,
        public array $sheets,
        public string $filename,
        public string $downloadUrl,
    ) {}
}
