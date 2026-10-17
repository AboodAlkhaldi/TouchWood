<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncRead;

use Modules\Sync\Application\Query\ListSyncReads\ReadView;
use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\Report\ReportEntryView;
use Modules\Sync\Application\Report\SizeNames;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\EntryNotFound;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Shared\Application\Unauthorized;

/**
 * **One read's page** (owner, 2026-10-17, sync.md §9.3 #19), for staff with View the Sync Report in its
 * store: its sections - prices changed and stock changed, before and after, and what it reported - each
 * line naming its size's product, sale or discount, to be changed there; the provider's prices stay
 * read-only. A read that is not there - or not yet named by any store this person may see - is not
 * found, the same way.
 */
final readonly class ViewSyncReadHandler
{
    public function __construct(
        private ReportAccess $access,
        private SyncLog $log,
        private SizeNames $names,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput|EntryNotFound
     */
    public function handle(ViewSyncRead $query): ReadDetailView
    {
        $read = $this->log->read($query->readId) ?? throw new EntryNotFound;
        $this->access->authorize(SyncPermissions::REPORT_VIEW, $read['storeId']);
        $lines = $this->log->lines($read['id']);
        $reported = $this->log->entriesOf($read['id']);
        $names = $this->names->of([...array_column($lines, 'variantId'), ...array_column($reported, 'variantId')]);
        $line = static function (array $row) use ($names): ReadLineView {
            $size = $names[$row['variantId']] ?? null;

            return new ReadLineView($row['variantId'], $row['code'] ?? $size['code'] ?? null, $size['productId'] ?? null, $size['nameAr'] ?? null, $size['nameEn'] ?? null, $row['before'], $row['after']);
        };

        return new ReadDetailView(
            ReadView::of($read),
            array_values(array_map($line, array_filter($lines, static fn (array $row): bool => $row['kind'] === 'PRICE'))),
            array_values(array_map($line, array_filter($lines, static fn (array $row): bool => $row['kind'] === 'STOCK'))),
            array_map(static fn (array $row): ReportEntryView => ReportEntryView::of($row, $names), $reported),
        );
    }
}
