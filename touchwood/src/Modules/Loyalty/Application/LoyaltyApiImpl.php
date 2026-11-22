<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Loyalty\Application\Command\ConfirmRedemption\ConfirmRedemptionHandler;
use Modules\Loyalty\Application\Command\Earn\Earn;
use Modules\Loyalty\Application\Command\Earn\EarnHandler;
use Modules\Loyalty\Application\Command\Redeem\Redeem;
use Modules\Loyalty\Application\Command\Redeem\RedeemHandler;
use Modules\Loyalty\Application\Command\ReleaseRedemption\ReleaseRedemptionHandler;
use Modules\Loyalty\Application\Command\ReserveRedemption\ReserveRedemptionHandler;
use Modules\Loyalty\Application\Command\SettleCancelledOrder\SettleCancelledOrder;
use Modules\Loyalty\Application\Command\SettleCancelledOrder\SettleCancelledOrderHandler;
use Modules\Loyalty\Application\Command\SettleEditedOrder\SettleEditedOrder;
use Modules\Loyalty\Application\Command\SettleEditedOrder\SettleEditedOrderHandler;
use Modules\Loyalty\Application\Command\SettleReturnedOrder\SettleReturnedOrder;
use Modules\Loyalty\Application\Command\SettleReturnedOrder\SettleReturnedOrderHandler;
use Modules\Loyalty\Application\Points\SalesReads;
use Modules\Loyalty\Application\Query\PointsReader;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\EarnPoints;
use Modules\Loyalty\Public\Dto\EditedPoints;
use Modules\Loyalty\Public\Dto\EditedPointsRequest;
use Modules\Loyalty\Public\Dto\ExpiringPointsDto;
use Modules\Loyalty\Public\Dto\ExpiringPointsPageDto;
use Modules\Loyalty\Public\Dto\PointsBalanceDto;
use Modules\Loyalty\Public\Dto\PointsByKindDto;
use Modules\Loyalty\Public\Dto\PointsEntryDto;
use Modules\Loyalty\Public\Dto\RedeemPoints;
use Modules\Loyalty\Public\Dto\RedemptionQuote;
use Modules\Loyalty\Public\Dto\RedemptionRequest;
use Modules\Loyalty\Public\Dto\ReservePoints;
use Modules\Loyalty\Public\Dto\SettleEdit;
use Modules\Loyalty\Public\Dto\SettleOrder;
use Modules\Loyalty\Public\Dto\SettlePointsHold;
use Modules\Loyalty\Public\Dto\SettleReturn;
use Modules\Loyalty\Public\Enums\EntryKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Loyalty's contract for Sales (loyalty.md §2.1): the writes through their handlers, which check the
 * caller's permission in the order's store; the reads through `SalesReads`, which check none.
 */
final readonly class LoyaltyApiImpl implements LoyaltyApi
{
    /** The most customers a page of `expiring` holds. */
    public const int EXPIRING_MAX = 1000;

    public function __construct(
        private SalesReads $reads,
        private RedeemHandler $redeem,
        private EarnHandler $earn,
        private SettleCancelledOrderHandler $cancelled,
        private SettleReturnedOrderHandler $returned,
        private SettleEditedOrderHandler $edited,
        private PointsReader $reader,
        private ReserveRedemptionHandler $reserve,
        private ConfirmRedemptionHandler $confirm,
        private ReleaseRedemptionHandler $release,
    ) {}

    public function reserveRedemption(ReservePoints $change): void
    {
        $this->reserve->handle($change);
    }

    public function confirmRedemption(SettlePointsHold $change): void
    {
        $this->confirm->handle($change);
    }

    public function releaseRedemption(SettlePointsHold $change): void
    {
        $this->release->handle($change);
    }

    public function earningPreview(string $storeId, Money $goodsTotal): int
    {
        return $this->reads->earningPreview($storeId, $goodsTotal);
    }

    public function quoteRedemption(RedemptionRequest $request): RedemptionQuote
    {
        return $this->reads->quoteRedemption($request);
    }

    public function redeem(RedeemPoints $change): void
    {
        $this->redeem->handle(new Redeem($change));
    }

    public function earn(EarnPoints $change): void
    {
        $this->earn->handle(new Earn($change));
    }

    public function balance(string $customerId, string $storeId): PointsBalanceDto
    {
        return $this->reads->balance($customerId, $storeId);
    }

    public function orderCancelled(SettleOrder $change): void
    {
        $this->cancelled->handle(new SettleCancelledOrder($change));
    }

    public function orderReturned(SettleReturn $change): void
    {
        $this->returned->handle(new SettleReturnedOrder($change));
    }

    public function pointsAfterEdit(EditedPointsRequest $request): EditedPoints
    {
        return $this->reads->pointsAfterEdit($request);
    }

    public function orderEdited(SettleEdit $change): EditedPoints
    {
        return $this->edited->handle(new SettleEditedOrder($change));
    }

    public function entryForMessage(int $entryId): ?PointsEntryDto
    {
        $row = $entryId > 0 ? $this->reader->entry($entryId) : null;

        return $row === null ? null : new PointsEntryDto(
            $row['id'], $row['store_id'], $row['customer_id'], EntryKind::from($row['kind']), $row['points'], $row['reason'],
            $row['order_number'], $row['expires_at'] === null ? null : CarbonImmutable::parse($row['expires_at']), $row['balance'],
        );
    }

    public function expiring(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after = null, int $limit = 100): ExpiringPointsPageDto
    {
        $limit = min(max($limit, 1), self::EXPIRING_MAX);
        $after = $after !== null && Ulids::valid($after) ? strtolower($after) : null;
        $rows = $from < $to ? $this->reader->expiring($store->value, $from, $to, $after, $limit + 1) : [];
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return new ExpiringPointsPageDto(
            array_map(static fn (array $row): ExpiringPointsDto => new ExpiringPointsDto($row[0], $row[1]), $rows),
            $more && $rows !== [] ? $rows[count($rows) - 1][0] : null,
        );
    }

    public function pointsByKind(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $from < $to ? array_map(
            static fn (array $row): PointsByKindDto => new PointsByKindDto(EntryKind::from($row[0]), $row[1], $row[2]),
            $this->reader->byKind($store->value, $from, $to),
        ) : [];
    }
}
