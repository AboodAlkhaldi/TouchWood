<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Loyalty\Domain\Model\OrderPoints;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * The orders' points and the ledger's writes on the query builder (loyalty.md §1.12): no Eloquent
 * model. Every write runs on an account its caller has locked; the lots it spends are locked after
 * the account, in the order they are spent (lesson 37). An order's row is locked alone — never
 * another account's row with it.
 */
final readonly class DatabasePointsLedger implements PointsLedger
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function order(string $orderId): ?OrderPoints
    {
        return $this->orderRow($orderId, lock: false);
    }

    public function lockOrder(string $orderId): ?OrderPoints
    {
        return $this->orderRow($orderId, lock: true);
    }

    public function redeem(
        PointsAccount $account,
        string $orderId,
        string $orderNumber,
        string $currencyCode,
        int $points,
        int $discountMinor,
        int $capPercent,
        DateTimeImmutable $now,
    ): bool {
        $this->guard();

        // Inserted only if absent: an order recorded meanwhile — by a call for another customer or
        // store, under another account's lock — is answered, never a unique-key failure (lesson 64).
        $recorded = $this->db->table('loyalty.orders')->insertOrIgnore([
            'order_id' => strtolower($orderId),
            'order_number' => $orderNumber,
            'account_id' => $account->id,
            'store_id' => $account->store->value,
            'currency_code' => $currencyCode,
            'discount_minor' => $discountMinor,
            'points_redeemed' => $points,
            'redemption_cap_percent' => $capPercent,
        ]);

        if ($recorded === 0) {
            return false;
        }

        $lots = $this->spendable($account, $now);
        $entry = $this->entry($account, 'REDEEMED', -$points, $orderId, $now);
        $left = $points;

        foreach ($lots as $lot) {
            if ($left === 0) {
                break;
            }

            $taken = min($left, (int) $lot->points_left);
            $this->db->table('loyalty.takes')->insert([
                'account_id' => $account->id, 'lot_id' => (int) $lot->id, 'entry_id' => $entry, 'kind' => 'REDEMPTION', 'points' => $taken,
            ]);
            $this->move($account, (int) $lot->id, $entry, -$taken);
            $left -= $taken;
        }

        if ($left > 0) {
            throw new LogicException('A redemption took more points than the account\'s live lots hold.');
        }

        $this->db->table('loyalty.accounts')
            ->where('id', $account->id)
            ->where('store_id', $account->store->value)
            ->decrement('balance', $points, ['updated_at' => Moment::of($now)]);

        return true;
    }

    public function earn(
        PointsAccount $account,
        string $orderId,
        string $orderNumber,
        string $currencyCode,
        int $goodsTotalMinor,
        int $points,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): bool {
        $this->guard();
        $orderId = strtolower($orderId);
        $delivery = ['goods_total_minor' => $goodsTotalMinor, 'points_earned' => $points, 'delivered_at' => Moment::of($now)];

        // An order that redeemed points is recorded already, on this account; one that did not is
        // recorded now — unless a call for another account recorded it meanwhile.
        $recorded = $this->db->table('loyalty.orders')
            ->where('order_id', $orderId)
            ->where('account_id', $account->id)
            ->update($delivery) > 0
            || $this->db->table('loyalty.orders')->insertOrIgnore([
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'account_id' => $account->id,
                'store_id' => $account->store->value,
                'currency_code' => $currencyCode,
                ...$delivery,
            ]) > 0;

        if (! $recorded) {
            return false;
        }

        if ($points === 0) {
            return true;
        }

        $entry = $this->entry($account, 'EARNED', $points, $orderId, $now);
        $lot = $this->db->table('loyalty.lots')->insertGetId([
            'account_id' => $account->id,
            'entry_id' => $entry,
            'order_id' => $orderId,
            'points' => $points,
            'points_left' => $points,
            'expires_at' => Moment::of($expiresAt),
        ]);
        $this->db->table('loyalty.lot_movements')->insert([
            'account_id' => $account->id, 'lot_id' => $lot, 'entry_id' => $entry, 'points' => $points,
        ]);
        $this->db->table('loyalty.accounts')
            ->where('id', $account->id)
            ->where('store_id', $account->store->value)
            ->increment('balance', $points, ['updated_at' => Moment::of($now)]);

        return true;
    }

    public function add(PointsAccount $account, int $points, string $staffId, string $reason, DateTimeImmutable $expiresAt, DateTimeImmutable $now): void
    {
        $this->guard();

        $entry = $this->byHand($account, 'ADDED', $points, $staffId, $reason, $now);
        $lot = $this->db->table('loyalty.lots')->insertGetId([
            'account_id' => $account->id,
            'entry_id' => $entry,
            'points' => $points,
            'points_left' => $points,
            'expires_at' => Moment::of($expiresAt),
        ]);
        $this->db->table('loyalty.lot_movements')->insert([
            'account_id' => $account->id, 'lot_id' => $lot, 'entry_id' => $entry, 'points' => $points,
        ]);
        $this->db->table('loyalty.accounts')
            ->where('id', $account->id)
            ->where('store_id', $account->store->value)
            ->increment('balance', $points, ['updated_at' => Moment::of($now)]);
    }

    public function deduct(PointsAccount $account, int $points, string $staffId, string $reason, DateTimeImmutable $now): void
    {
        $this->guard();

        $entry = $this->byHand($account, 'DEDUCTED', -$points, $staffId, $reason, $now);
        $left = $points;

        // No take: removed points never come back (§1.9) — only each lot's movement.
        foreach ($this->spendable($account, $now) as $lot) {
            if ($left === 0) {
                break;
            }

            $taken = min($left, (int) $lot->points_left);
            $this->move($account, (int) $lot->id, $entry, -$taken);
            $left -= $taken;
        }

        if ($left > 0) {
            throw new LogicException('A deduction took more points than the account\'s live lots hold.');
        }

        $this->db->table('loyalty.accounts')
            ->where('id', $account->id)
            ->where('store_id', $account->store->value)
            ->decrement('balance', $points, ['updated_at' => Moment::of($now)]);
    }

    /**
     * The lots points are spent from, locked after the account: the one expiring first first, equal
     * dates the older lot first (§1.3). Dead lots were expired when the account was locked.
     *
     * @return list<stdClass>
     */
    private function spendable(PointsAccount $account, DateTimeImmutable $now): array
    {
        /** @var list<stdClass> */
        return $this->db->table('loyalty.lots')
            ->where('account_id', $account->id)
            ->where('points_left', '>', 0)
            ->where('expires_at', '>', Moment::of($now))
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();
    }

    /** A change by hand's entry: no order, the staff member and the reason (§1.9). */
    private function byHand(PointsAccount $account, string $kind, int $points, string $staffId, string $reason, DateTimeImmutable $now): int
    {
        return (int) $this->db->table('loyalty.entries')->insertGetId([
            'account_id' => $account->id,
            'store_id' => $account->store->value,
            'kind' => $kind,
            'points' => $points,
            'staff_id' => $staffId,
            'reason' => $reason,
            'occurred_at' => Moment::of($now),
        ]);
    }

    private function entry(PointsAccount $account, string $kind, int $points, string $orderId, DateTimeImmutable $now): int
    {
        return (int) $this->db->table('loyalty.entries')->insertGetId([
            'account_id' => $account->id,
            'store_id' => $account->store->value,
            'kind' => $kind,
            'points' => $points,
            'order_id' => strtolower($orderId),
            'occurred_at' => Moment::of($now),
        ]);
    }

    /** One lot's movement, and the lot's points left after it. */
    private function move(PointsAccount $account, int $lot, int $entry, int $points): void
    {
        $this->db->table('loyalty.lot_movements')->insert([
            'account_id' => $account->id, 'lot_id' => $lot, 'entry_id' => $entry, 'points' => $points,
        ]);
        $this->db->table('loyalty.lots')->where('id', $lot)->where('account_id', $account->id)->increment('points_left', $points);
    }

    private function guard(): void
    {
        // The account's lock — and every row written here — lasts only as long as the caller's
        // transaction; outside one it would have ended already.
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Points are written inside the change\'s transaction.');
        }
    }

    private function orderRow(string $orderId, bool $lock): ?OrderPoints
    {
        if (! Ulids::valid($orderId)) {
            return null;
        }

        $query = $this->db->table('loyalty.orders')->where('order_id', strtolower($orderId));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        return new OrderPoints(
            (string) $row->order_id,
            (string) $row->order_number,
            (string) $row->account_id,
            StoreId::fromString((string) $row->store_id),
            (int) $row->points_redeemed,
            (int) $row->discount_minor,
            (int) $row->points_earned,
            $row->delivered_at !== null,
            $row->cancelled_at !== null,
        );
    }
}
