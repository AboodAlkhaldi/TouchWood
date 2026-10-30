<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use DateTimeImmutable;
use Modules\B2B\Public\Dto\ApplicationCountsDto;
use Modules\Catalog\Public\Dto\SearchSummaryRowDto;
use Modules\Feedback\Public\Dto\FeedbackCountsDto;
use Modules\Inventory\Public\Dto\LowStockChangesDto;
use Modules\Loyalty\Public\Dto\PointsByKindDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Promotions\Public\Dto\CouponsUsedDto;
use Modules\Sales\Public\Dto\MoneyDaysDto;
use Modules\Sales\Public\Dto\SalesCountsDto;
use Modules\Sales\Public\Dto\TransfersWaitingDto;
use Modules\Sales\Public\Dto\WaitingRefundDto;
use Modules\Sync\Public\Dto\SyncDayDto;
use Shared\Domain\ValueObject\Money;

/**
 * One store's daily report (ops.md §1.11): its own day, from midnight in the store's time zone until the
 * report was made (or the next midnight, for a past day), every section in the spec's order. Every figure
 * is what the owning module's read answered at that moment - Ops counts nothing itself.
 */
final readonly class StoreDayReport
{
    /**
     * @param  list<WaitingRefundDto>  $waitingRefunds
     * @param  list<SizeSold>  $topSizes
     * @param  list<CategoryRevenue>  $categories
     * @param  list<SearchSummaryRowDto>  $topSearches
     * @param  list<SearchSummaryRowDto>  $topNoResults
     * @param  list<PointsByKindDto>  $otherPoints
     */
    public function __construct(
        public StoreDto $store,
        /** Midnight of the day, in the store's time zone. */
        public DateTimeImmutable $from,
        /** The moment the report stops: when it was made, or the next midnight for a past day. */
        public DateTimeImmutable $to,
        public bool $pastDay,

        // 1. Today against yesterday, same hours.
        public PeriodFigures $today,
        public PeriodFigures $yesterday,

        // 2. Sales.
        public SalesCountsDto $counts,
        public Money $vat,
        public Money $shipping,
        public Money $refunds,
        /** What the returns asked today and not finished would refund, estimated (§1.10: a second figure). */
        public Money $waitingRefundsTotal,
        public array $waitingRefunds,

        // 3. Money received.
        public MoneyDaysDto $money,
        public TransfersWaitingDto $transfersWaiting,

        // 4. Products.
        public array $topSizes,
        public array $categories,

        // 5. Customers.
        public int $newIndividuals,
        public int $newCompanies,
        public ApplicationCountsDto $applications,

        // 6. Stock.
        public LowStockChangesDto $lowStock,
        public SyncDayDto $sync,

        // 7. Promotions and points.
        public CouponsUsedDto $coupons,
        /** Points given: earned on delivery and added by hand (loyalty.md ledger kinds EARNED + ADDED). */
        public int $pointsGiven,
        /** Points spent at checkout (REDEEMED), as a positive number. */
        public int $pointsSpent,
        /** The ledger's other kinds - returned, reversed, deducted, expired - each only when it happened (owner, 2026-10-30). */
        public array $otherPoints,

        // 8. Feedback.
        public FeedbackCountsDto $feedback,

        // 9. Searches.
        public int $searches,
        public int $searchesWithNoResults,
        public array $topSearches,
        public array $topNoResults,

        // 10. Month to date.
        public PeriodFigures $monthToDate,
    ) {}
}
