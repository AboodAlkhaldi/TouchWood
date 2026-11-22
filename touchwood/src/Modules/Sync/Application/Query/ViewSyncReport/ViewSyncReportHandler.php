<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncReport;

use Modules\Sync\Application\Report\Cursor;
use Modules\Sync\Application\Report\Moment;
use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\Report\ReportEntryView;
use Modules\Sync\Application\Report\ReportLines;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Shared\Application\Unauthorized;

/**
 * **The Sync report** (sync.md §1.6, §4.1 Y1 Problems), for staff with View the Sync Report in that
 * store: the open entries, the latest seen first, 50 at a time after the last one shown; each naming
 * its size - product, values and code - or the code the provider sent, the sale it stopped or the
 * discount it skipped with whether it still exists (Q37), a failed read and its reason (Q31); and how
 * many are open of each kind, for the kind filter. A fixed few queries a page, however many entries.
 */
final readonly class ViewSyncReportHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private ReportAccess $access,
        private SyncLog $log,
        private ReportLines $lines,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput
     */
    public function handle(ViewSyncReport $query): SyncReportView
    {
        $store = $this->access->authorize(SyncPermissions::REPORT_VIEW, $query->storeId);
        $kind = $query->kind === null ? null : (ReportKind::tryFrom(strtoupper(trim($query->kind))) ?? throw new InvalidSyncInput('kind'));
        $after = Cursor::read($query->after, 'entry');

        $rows = $this->log->openEntries($store, $kind?->value, $after, self::PER_PAGE + 1);
        $more = count($rows) > self::PER_PAGE;
        $rows = array_slice($rows, 0, self::PER_PAGE);
        $open = $this->log->openByKind($store);
        $byKind = [];

        foreach (ReportKind::cases() as $each) {
            $byKind[$each->value] = $open[$each->value] ?? 0;
        }

        $about = $this->lines->describe($rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new SyncReportView(
            array_map(static fn (array $row, $described): ReportEntryView => new ReportEntryView($row['id'], $described, (string) Moment::iso($row['openedAt']), (string) Moment::iso($row['seenAt'])), $rows, $about),
            $more && $last !== null ? Cursor::of($last['seenAt'], $last['id']) : null,
            $kind === null ? array_sum($byKind) : $byKind[$kind->value],
            array_sum($byKind),
            $byKind,
        );
    }
}
