<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Repository;

use DateTimeImmutable;
use Modules\Loyalty\Domain\Model\OrderPoints;
use Modules\Loyalty\Domain\Model\PointsAccount;

/**
 * The orders' points and the ledger's writes (loyalty.md §1.2, §1.3, §1.6, §1.7), on an account the
 * caller has already locked through `PointsAccounts::lockOrCreate` — the account first, then its lots,
 * the one lock order everywhere (lesson 37). Each write is one entry, its lot movements, the lots and
 * the balance, together.
 */
interface PointsLedger
{
    /** What Loyalty has recorded of the order; null when nothing yet. A read: nothing is locked. */
    public function order(string $orderId): ?OrderPoints;

    /** The same, its row locked until the caller's transaction ends: read again under the account's lock. */
    public function lockOrder(string $orderId): ?OrderPoints;

    /**
     * Records the order with its discount and the cap it redeemed under (§1.8), and takes `$points`
     * from the locked account's live lots, the one expiring first first (§1.3): one REDEEMED entry, a
     * take and a movement per lot. The caller has checked the balance covers it.
     *
     * @return bool false, with nothing written, when the order was recorded meanwhile for another
     *              account — a call for another customer or store
     */
    public function redeem(
        PointsAccount $account,
        string $orderId,
        string $orderNumber,
        string $currencyCode,
        int $points,
        int $discountMinor,
        int $capPercent,
        DateTimeImmutable $now,
        /** The lots spent are those live at this moment - a held redemption's own (§9.6); default `$now`. */
        ?DateTimeImmutable $liveAt = null,
    ): bool;

    /**
     * Records the order delivered with its goods total and the points it earned (§1.6) — written even
     * at 0 points; above 0, one EARNED entry and a lot that expires at `$expiresAt`.
     *
     * @return int|null null, with nothing written, when the order is recorded for another account; else the
     *                  EARNED entry's id, 0 when it earned nothing
     */
    public function earn(
        PointsAccount $account,
        string $orderId,
        string $orderNumber,
        string $currencyCode,
        int $goodsTotalMinor,
        int $points,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): ?int;

    /** Points added by hand (§1.9): one ADDED entry with the staff member and reason, and a lot that expires at `$expiresAt`; answers the entry's id. */
    public function add(PointsAccount $account, int $points, string $staffId, string $reason, DateTimeImmutable $expiresAt, DateTimeImmutable $now): int;

    /**
     * Points removed by hand (§1.9): one DEDUCTED entry, taken from the live lots expiring first, a
     * movement per lot and no take — removed points never come back. The caller has checked the
     * balance covers it. Answers the entry's id.
     */
    public function deduct(PointsAccount $account, int $points, string $staffId, string $reason, DateTimeImmutable $now): int;
}
