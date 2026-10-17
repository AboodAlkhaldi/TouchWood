<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncRead;

use Modules\Sync\Application\Query\ListSyncReads\ReadView;
use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\Report\ReportedView;
use Modules\Sync\Application\Report\SizeNames;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\ReadNotFound;
use Shared\Application\Unauthorized;

/**
 * **One read's page** (owner, 2026-10-17, sync.md §9.3 #19), for staff with View the Sync Report in its
 * store: prices and stock changed, before and after, and what it reported as it was that day (owner,
 * 2026-10-18) - each line naming its size's product, sale or discount, to be changed there; the
 * provider's prices stay read-only. Each section a page at a time, so a store's first full read never
 * makes one page of thousands. The read is looked up first, to know its store - as Pricing finds a sale
 * before asking about its store - so a read that does not exist is not found for anyone.
 */
final readonly class ViewSyncReadHandler
{
    public const int PER_PAGE = 100;

    public function __construct(
        private ReportAccess $access,
        private SyncLog $log,
        private SizeNames $names,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput|ReadNotFound
     */
    public function handle(ViewSyncRead $query): ReadDetailView
    {
        $read = $this->log->read($query->readId) ?? throw new ReadNotFound;
        $this->access->authorize(SyncPermissions::REPORT_VIEW, $read['storeId']);
        $page = max(1, $query->page);
        [$prices, $pricesTotal] = $this->log->lines($read['id'], 'PRICE', $page, self::PER_PAGE);
        [$stock, $stockTotal] = $this->log->lines($read['id'], 'STOCK', $page, self::PER_PAGE);
        [$reported, $reportedTotal] = $this->log->reported($read['id'], $page, self::PER_PAGE);
        $names = $this->names->of([...array_column($prices, 'variantId'), ...array_column($stock, 'variantId'), ...array_column($reported, 'variantId')]);
        $line = static function (array $row) use ($names): ReadLineView {
            $size = $names[$row['variantId']] ?? null;

            return new ReadLineView($row['variantId'], $row['code'] ?? $size['code'] ?? null, $size['productId'] ?? null, $size['nameAr'] ?? null, $size['nameEn'] ?? null, $row['before'], $row['after']);
        };

        return new ReadDetailView(
            ReadView::of($read),
            $read['codes'],
            array_map($line, $prices),
            $pricesTotal,
            array_map($line, $stock),
            $stockTotal,
            array_map(static function (array $row) use ($names): ReportedView {
                $size = $row['variantId'] === null ? null : ($names[$row['variantId']] ?? null);

                return new ReportedView($row['kind'], $row['code'] ?? $size['code'] ?? null, $row['variantId'], $size['productId'] ?? null, $size['nameAr'] ?? null, $size['nameEn'] ?? null, $row['saleId'], $row['detail']);
            }, $reported),
            $reportedTotal,
            $page,
            self::PER_PAGE,
        );
    }
}
