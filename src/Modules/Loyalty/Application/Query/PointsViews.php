<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Loyalty\Application\Points\ProgrammeReader;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Service\PointsArithmetic;
use Modules\Platform\Public\Contracts\StaffNames;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * A customer's points in a store as a screen reads them (loyalty.md §3): only live lots count, and
 * nothing is written — a dead lot is never counted, nor expired by a read (§1.1). For staff, each
 * change by hand names its admin as the reader may see them (a Super Admin withheld, handoff §14) and
 * its reason; for the customer, neither.
 */
final readonly class PointsViews
{
    /** Enough history for a screen. */
    public const int PER_PAGE = 50;

    public function __construct(
        private PointsAccounts $accounts,
        private PointsReader $reader,
        private ProgrammeReader $programmes,
        private StaffNames $staffNames,
    ) {}

    public function of(?PointsAccount $account, StoreId $store, bool $forStaff, ?DateTimeImmutable $beforeAt, ?int $beforeId, int $perPage): PointsView
    {
        $programme = $this->programmes->of($store);
        $perPage = min(max($perPage, 1), self::PER_PAGE);
        $now = CarbonImmutable::now();
        $lots = $account === null ? [] : $this->accounts->liveLots($account, $now);
        $balance = array_sum(array_map(static fn ($lot): int => $lot->pointsLeft, $lots));
        // One more than a page, to learn whether there is another without counting the ledger.
        $rows = $account === null ? [] : $this->reader->history($account->id, $beforeAt, $beforeId, $perPage + 1);
        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        $rows = $forStaff ? $this->named($rows) : array_map(static fn (PointsHistoryRow $row): PointsHistoryRow => $row->forCustomer(), $rows);
        $last = $more ? end($rows) : false;

        return new PointsView(
            $balance,
            Money::of(PointsArithmetic::worth($balance, $programme->pointsPerUnitOff, $programme->exponent), $programme->currencyCode),
            $lots,
            $rows,
            $last === false ? null : $last->occurredAt,
            $last === false ? null : $last->id,
        );
    }

    /**
     * @param  list<PointsHistoryRow>  $rows
     * @return list<PointsHistoryRow>
     */
    private function named(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (PointsHistoryRow $row): ?string => $row->staffId, $rows))));
        $names = $ids === [] ? [] : $this->staffNames->forReader($ids);

        return array_map(static function (PointsHistoryRow $row) use ($names): PointsHistoryRow {
            $name = $row->staffId === null ? null : ($names[strtolower($row->staffId)] ?? null);

            return $name === null ? $row : $row->named($name->name, $name->hidden);
        }, $rows);
    }
}
