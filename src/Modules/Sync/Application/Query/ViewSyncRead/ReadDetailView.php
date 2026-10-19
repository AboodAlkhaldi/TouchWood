<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncRead;

use Modules\Sync\Application\Query\ListSyncReads\ReadView;
use Modules\Sync\Application\Report\ReportedView;

/**
 * **One read's page** (sync.md §4.1 Y2): its store (the breadcrumb's code, its zone for the moments), the
 * header as the page of reads writes it, how many lines each section holds (the tabs), and the section
 * asked for - one page of 100 of it, and whether more follow (Show More). Only the asked section's rows
 * are read: `lines` for Prices or Stock, `reported` for Reported, `codes` for Codes Asked.
 */
final readonly class ReadDetailView
{
    /**
     * @param  array{prices: int, stock: int, reported: int, codes: int}  $sections
     * @param  list<ReadLineView>  $lines
     * @param  list<ReportedView>  $reported
     * @param  list<string>  $codes
     */
    public function __construct(
        public ReadView $read,
        public string $storeId,
        public string $storeCode,
        public string $storeTimezone,
        public array $sections,
        public string $section,
        public int $page,
        public int $perPage,
        public bool $more,
        public array $lines,
        public array $reported,
        public array $codes,
    ) {}
}
