<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **A read** (sync.md §4.1 Y2): its store, its header, each section's count, and the section shown - one
 * page of 100 and whether more follow; links drawn only where the reader may open them.
 */
#[TypeScript]
final class SyncReadPage extends Data
{
    /**
     * @param  list<ReadLineData>  $lines
     * @param  list<ReportedData>  $reported
     * @param  list<string>  $codes
     */
    public function __construct(
        public string $storeCode,
        public string $storeTimezone,
        public ReadRowData $read,
        public SectionTotalsData $sections,
        public string $section,
        public int $page,
        public int $perPage,
        public bool $more,
        public array $lines,
        public array $reported,
        public array $codes,
        public bool $mayViewProducts,
        public bool $mayViewOffers,
    ) {}
}
