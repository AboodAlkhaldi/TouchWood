<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use Modules\Platform\Public\Contracts\StaffNames;
use Modules\Sync\Application\Query\ListSyncReads\ReadView;

/**
 * **Reads as the screens show them** (sync.md §4.1 Y1 Reads, Y2's header): who asked by name - Platform's
 * `StaffNames`, which hides a Super Admin from anyone but another - or "The System" (Q36); a failed read's
 * counts from what it wrote (Q30), one query for the page's failed reads; its reason written (Q31); every
 * moment ISO 8601. Its rows are `SyncLog`'s.
 */
final readonly class ReadViews
{
    public function __construct(
        private SyncLog $log,
        private StaffNames $staff,
        private SyncWords $words,
    ) {}

    /**
     * @param  list<array{id: string, storeId: string, kind: string, state: string, codes: list<string>|null, requestedById: string|null, pricesChanged: int, stockChanged: int, skipped: int, error: string|null, queuedAt: string, startedAt: string|null, finishedAt: string|null}>  $rows
     * @return list<ReadView>
     */
    public function of(array $rows): array
    {
        $asked = array_values(array_unique(array_filter(array_column($rows, 'requestedById'))));
        $names = $asked === [] ? [] : $this->staff->forReader($asked);
        $failed = array_values(array_map(static fn (array $row): string => $row['id'], array_filter($rows, static fn (array $row): bool => $row['state'] === 'FAILED')));
        $written = $this->log->countsWritten($failed);
        $system = $this->words->line('admin_report.the_system');

        return array_map(function (array $row) use ($names, $written, $system): ReadView {
            $name = $row['requestedById'] === null ? null : ($names[strtolower($row['requestedById'])] ?? null);
            $counts = $written[$row['id']] ?? ['prices' => $row['pricesChanged'], 'stock' => $row['stockChanged'], 'skipped' => $row['skipped']];

            return new ReadView(
                $row['id'],
                $row['kind'],
                $row['state'],
                $name->name ?? $system,
                $name === null,
                $row['codes'] === null ? null : count($row['codes']),
                $counts['prices'],
                $counts['stock'],
                $counts['skipped'],
                FailureView::of($row['error'], $this->words),
                (string) Moment::iso($row['queuedAt']),
                Moment::iso($row['startedAt']),
                Moment::iso($row['finishedAt']),
                $row['state'] === 'DONE' || $row['state'] === 'FAILED' ? Moment::took($row['startedAt'], $row['finishedAt']) : null,
            );
        }, $rows);
    }
}
