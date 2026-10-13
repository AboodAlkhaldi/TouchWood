<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Repository;

use DateTimeImmutable;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Model\Settlement\OrderPointsFacts;
use Modules\Loyalty\Domain\Model\Settlement\SettlementKind;
use Modules\Loyalty\Domain\Model\Settlement\SettlementSnapshot;
use Modules\Loyalty\Domain\Service\Settlement;

/**
 * Settlements on the ledger (loyalty.md §1.8, §5): what one reads under the account's lock, and its
 * moves written — the entries and their movements, the lots, the takes, the covers, the order's
 * running totals, and the settlement itself, once per return, edit or cancellation.
 */
interface PointsSettlements
{
    /** The order's facts and running totals; null when Loyalty has no record of it. A read. */
    public function facts(string $orderId): ?OrderPointsFacts;

    /** Whether this return or edit was settled already. */
    public function recorded(string $orderId, string $returnId): bool;

    /**
     * The snapshot, read after the caller locked the account and the order's row: every lot of the
     * account locked, in spending order.
     */
    public function snapshot(PointsAccount $account, string $orderId): SettlementSnapshot;

    /** Writes a settlement worked out on that snapshot. */
    public function record(
        PointsAccount $account,
        string $orderId,
        SettlementKind $kind,
        ?string $returnId,
        Settlement $settlement,
        int $goodsShareMinor,
        int $discountShareMinor,
        DateTimeImmutable $now,
    ): void;
}
