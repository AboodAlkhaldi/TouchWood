<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\SyncStatus;

use Modules\Sync\Application\Report\FailureView;
use Modules\Sync\Application\Report\Moment;
use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\Report\SyncWords;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Shared\Application\Unauthorized;

/**
 * **A store's status line** (sync.md §4.1.3 `SyncStatus`, S8), for staff with View the Sync Report there:
 * the provider and the state with its reason, the last read and the last full read (Q29), and a read
 * running or waiting - one query. A store not wired says so (Q35); its reads are still listed.
 */
final readonly class SyncStatusHandler
{
    public function __construct(
        private ReportAccess $access,
        private SyncLog $log,
        private SyncWords $words,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput
     */
    public function handle(SyncStatus $query): SyncStatusView
    {
        $status = $this->log->status($this->access->authorize(SyncPermissions::REPORT_VIEW, $query->storeId));
        $wired = $status['provider'] !== null;

        return new SyncStatusView(
            $status['provider'],
            $wired ? $this->words->line("admin_report.providers.{$status['provider']}") : null,
            match (true) {
                ! $wired => SyncStatusView::NOT_WIRED,
                $status['state'] === 'FAILING' => SyncStatusView::FAILING,
                default => SyncStatusView::WORKING,
            },
            $wired && $status['state'] === 'FAILING' ? FailureView::of($status['lastError'], $this->words) : null,
            Moment::iso($status['lastReadAt']),
            $wired ? Moment::iso($status['lastFullReadAt']) : null,
            $status['running'] === null ? null : new ReadMomentView($status['running']['kind'], (string) Moment::iso($status['running']['at'])),
            $status['waiting'] === null ? null : new ReadMomentView($status['waiting']['kind'], (string) Moment::iso($status['waiting']['at'])),
        );
    }
}
