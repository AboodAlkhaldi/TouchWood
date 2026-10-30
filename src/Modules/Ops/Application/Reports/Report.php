<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

/**
 * A report as its reader asked for it (ops.md §1.10): in their language - right to left in Arabic - its
 * title, the line naming its store and period, its sheets, and the name of its file.
 */
final readonly class Report
{
    /**
     * @param  non-empty-list<ReportSheet>  $sheets
     */
    public function __construct(
        public ReportKind $kind,
        public string $locale,
        public string $title,
        /** The store or "All stores", and the days covered - or the moment, for a report of now. */
        public string $subtitle,
        public array $sheets,
        public string $filename,
    ) {}

    public function rightToLeft(): bool
    {
        return $this->locale === 'ar';
    }
}
