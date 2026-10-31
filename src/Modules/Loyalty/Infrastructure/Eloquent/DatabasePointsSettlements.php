<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Model\Settlement\CoverSource;
use Modules\Loyalty\Domain\Model\Settlement\Movement;
use Modules\Loyalty\Domain\Model\Settlement\OrderPointsFacts;
use Modules\Loyalty\Domain\Model\Settlement\SettlementCover;
use Modules\Loyalty\Domain\Model\Settlement\SettlementKind;
use Modules\Loyalty\Domain\Model\Settlement\SettlementLot;
use Modules\Loyalty\Domain\Model\Settlement\SettlementSnapshot;
use Modules\Loyalty\Domain\Model\Settlement\SettlementTake;
use Modules\Loyalty\Domain\Repository\PointsSettlements;
use Modules\Loyalty\Domain\Service\Settlement;
use Modules\Loyalty\Public\Enums\EntryKind;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * Settlements on the query builder (loyalty.md §1.8, §5). Read after the caller locked the account and
 * the order's row; every lot of the account is locked next, in spending order — the one lock order
 * (lesson 37). Written in one go: the settlement's row first (order_returns, or the cancellation),
 * then each kind of entry with its movements, the lots, the takes, the covers and the order's totals.
 */
final readonly class DatabasePointsSettlements implements PointsSettlements
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function facts(string $orderId): ?OrderPointsFacts
    {
        if (! Ulids::valid($orderId)) {
            return null;
        }

        $row = $this->db->table('loyalty.orders')->where('order_id', strtolower($orderId))->first();

        return $row instanceof stdClass ? self::orderFacts($row) : null;
    }

    public function recorded(string $orderId, string $returnId): bool
    {
        return $this->db->table('loyalty.order_returns')
            ->where('order_id', strtolower($orderId))
            ->where('return_id', strtolower($returnId))
            ->exists();
    }

    public function snapshot(PointsAccount $account, string $orderId): SettlementSnapshot
    {
        $orderId = strtolower($orderId);
        $order = $this->db->table('loyalty.orders')->where('order_id', $orderId)->where('account_id', $account->id)->first()
            ?? throw new LogicException('A settlement of an order the account does not hold.');

        /** @var array<int, SettlementLot> $lots */
        $lots = [];

        // Points held for an online payment in progress cover nothing (loyalty.md §9.6; gap 7.2 (a)): the
        // settlement sees the live lots less the held points, taken from the lots a redemption would
        // spend first. Its writes are changes, never the lots' totals, so the held points stay where they are.
        $held = (int) $this->db->table('loyalty.redemption_holds')->where('account_id', $account->id)->sum('points');
        $now = new DateTimeImmutable;

        foreach ($this->db->table('loyalty.lots')->where('account_id', $account->id)->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get() as $row) {
            /** @var stdClass $row */
            $left = (int) $row->points_left;
            $expiresAt = new DateTimeImmutable((string) $row->expires_at);

            if ($held > 0 && $left > 0 && $expiresAt > $now) {
                $hidden = min($held, $left);
                $left -= $hidden;
                $held -= $hidden;
            }

            $lots[(int) $row->id] = new SettlementLot((int) $row->id, $left, $expiresAt, $row->order_id === null ? null : (string) $row->order_id);
        }

        $orderTakes = array_map(
            self::take(...),
            $this->db->table('loyalty.takes')
                ->join('loyalty.entries', 'loyalty.entries.id', '=', 'loyalty.takes.entry_id')
                ->where('loyalty.takes.account_id', $account->id)
                ->where('loyalty.takes.kind', 'REDEMPTION')
                ->where('loyalty.entries.kind', 'REDEEMED')
                ->where('loyalty.entries.order_id', $orderId)
                ->orderBy('loyalty.takes.id')
                ->get(['loyalty.takes.*'])
                ->all(),
        );

        $this->attachCovers($account, $lots);
        $earned = $this->db->table('loyalty.lots')->where('account_id', $account->id)->where('order_id', $orderId)->value('id');

        return new SettlementSnapshot(
            self::orderFacts($order),
            array_values($orderTakes),
            $lots,
            $earned === null ? null : (int) $earned,
            $earned === null ? 0 : $this->gone((int) $earned),
        );
    }

    public function record(
        PointsAccount $account,
        string $orderId,
        SettlementKind $kind,
        ?string $returnId,
        Settlement $settlement,
        int $goodsShareMinor,
        int $discountShareMinor,
        DateTimeImmutable $now,
    ): void {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Points are written inside the change\'s transaction.');
        }

        $orderId = strtolower($orderId);
        $returnId = $returnId === null ? null : strtolower($returnId);
        $moment = Moment::of($now);

        // The settlement's own row first: every entry it writes is keyed to it.
        if ($returnId !== null) {
            $this->db->table('loyalty.order_returns')->insert([
                'order_id' => $orderId, 'account_id' => $account->id, 'return_id' => $returnId,
                'returned_goods_minor' => $goodsShareMinor, 'returned_discount_minor' => $discountShareMinor, 'settled_at' => $moment,
            ]);
        }

        $entries = [];

        foreach ([EntryKind::RedemptionReturned, EntryKind::EarningReversed, EntryKind::Expired] as $entryKind) {
            $points = $settlement->total($entryKind);

            if ($points !== 0) {
                $entries[$entryKind->value] = (int) $this->db->table('loyalty.entries')->insertGetId([
                    'account_id' => $account->id, 'store_id' => $account->store->value, 'kind' => $entryKind->value,
                    'points' => $points, 'order_id' => $orderId, 'return_id' => $returnId, 'occurred_at' => $moment,
                ]);
            }
        }

        $net = [];

        foreach ($settlement->movements() as $move) {
            $this->movement($account, $move, $entries);
            $net[$move->lotId] = ($net[$move->lotId] ?? 0) + $move->points;
        }

        foreach ($net as $lotId => $points) {
            if ($points !== 0) {
                $this->db->table('loyalty.lots')->where('id', $lotId)->where('account_id', $account->id)->increment('points_left', $points);
            }
        }

        foreach ($settlement->takesBack() as $takeId => $points) {
            $this->db->table('loyalty.takes')->where('id', $takeId)->where('account_id', $account->id)->increment('back', $points);
        }

        $takeIds = [];

        foreach ($settlement->newTakes() as $take) {
            $takeIds[spl_object_id($take)] = (int) $this->db->table('loyalty.takes')->insertGetId([
                'account_id' => $account->id, 'lot_id' => $take->lotId, 'kind' => 'COVER', 'points' => $take->points, 'back' => $take->back,
                'entry_id' => $entries[EntryKind::EarningReversed->value] ?? throw new LogicException('A cover take with no reversal to belong to.'),
            ]);
        }

        foreach ($settlement->newCovers() as $cover) {
            $this->db->table('loyalty.covers')->insert([
                'account_id' => $account->id, 'lot_id' => $cover->lotId, 'order_id' => $orderId, 'return_id' => $returnId,
                'source' => $cover->source->value, 'points' => $cover->points, 'undone' => $cover->undone,
                'source_take_id' => $cover->sourceTake === null ? null : ($takeIds[spl_object_id($cover->sourceTake)] ?? throw new LogicException('A cover\'s take was not written.')),
            ]);
        }

        foreach ($settlement->coversUndone() as $coverId => $points) {
            $this->db->table('loyalty.covers')->where('id', $coverId)->where('account_id', $account->id)->increment('undone', $points);
        }

        $facts = $settlement->facts;
        $this->db->table('loyalty.orders')->where('order_id', $orderId)->where('account_id', $account->id)->update([
            'returned_goods_minor' => $facts->returnedGoodsMinor,
            'returned_discount_minor' => $facts->returnedDiscountMinor,
            'points_given_back' => $facts->pointsGivenBack,
            'points_back_through_covers' => $facts->pointsBackThroughCovers,
            'points_reversed' => $facts->pointsReversed,
            'points_counted_gone' => $facts->pointsCountedGone,
            'points_covered' => $facts->pointsCovered,
            'points_dropped' => $facts->pointsDropped,
            'expired_back_unused' => $facts->expiredBackUnused,
            ...($kind === SettlementKind::Cancellation ? ['cancelled_at' => $moment] : []),
        ]);

        $balance = array_sum($net);

        if ($balance !== 0) {
            $this->db->table('loyalty.accounts')->where('id', $account->id)->where('store_id', $account->store->value)
                ->increment('balance', $balance, ['updated_at' => $moment]);
        }
    }

    /**
     * @param  array<string, int>  $entries
     */
    private function movement(PointsAccount $account, Movement $move, array $entries): void
    {
        $this->db->table('loyalty.lot_movements')->insert([
            'account_id' => $account->id, 'lot_id' => $move->lotId, 'points' => $move->points,
            'entry_id' => $entries[$move->kind->value] ?? throw new LogicException("A movement with no {$move->kind->value} entry."),
        ]);
    }

    /**
     * The account's covers, oldest first, on their lots; a lot cover with the take that covered it.
     *
     * @param  array<int, SettlementLot>  $lots
     */
    private function attachCovers(PointsAccount $account, array $lots): void
    {
        $covers = $this->db->table('loyalty.covers')->where('account_id', $account->id)->orderBy('id')->get()->all();
        $takeIds = array_values(array_filter(array_map(static fn (stdClass $cover): ?int => $cover->source_take_id === null ? null : (int) $cover->source_take_id, $covers)));
        $takes = [];

        if ($takeIds !== []) {
            foreach ($this->db->table('loyalty.takes')->whereIn('id', $takeIds)->get() as $row) {
                /** @var stdClass $row */
                $takes[(int) $row->id] = self::take($row);
            }
        }

        foreach ($covers as $row) {
            $lot = $lots[(int) $row->lot_id] ?? throw new LogicException('A cover on a lot outside the account.');
            $lot->covers[] = new SettlementCover(
                (int) $row->id,
                $lot->id,
                CoverSource::from((string) $row->source),
                $row->source_take_id === null ? null : ($takes[(int) $row->source_take_id] ?? throw new LogicException('A cover whose take is not the account\'s.')),
                (int) $row->points,
                (int) $row->undone,
            );
        }
    }

    /** Points that left a lot by expiry — those that came back to it expired included — or by hand. */
    private function gone(int $lotId): int
    {
        return -(int) $this->db->table('loyalty.lot_movements')
            ->join('loyalty.entries', 'loyalty.entries.id', '=', 'loyalty.lot_movements.entry_id')
            ->where('loyalty.lot_movements.lot_id', $lotId)
            ->whereIn('loyalty.entries.kind', ['EXPIRED', 'DEDUCTED'])
            ->sum('loyalty.lot_movements.points');
    }

    private static function take(stdClass $row): SettlementTake
    {
        return new SettlementTake((int) $row->id, (int) $row->lot_id, (int) $row->points, (int) $row->back);
    }

    private static function orderFacts(stdClass $row): OrderPointsFacts
    {
        return new OrderPointsFacts(
            (int) $row->points_redeemed,
            (int) $row->discount_minor,
            (int) $row->points_earned,
            $row->goods_total_minor === null ? null : (int) $row->goods_total_minor,
            (int) $row->returned_goods_minor,
            (int) $row->returned_discount_minor,
            (int) $row->points_given_back,
            (int) $row->points_back_through_covers,
            (int) $row->points_reversed,
            (int) $row->points_counted_gone,
            (int) $row->points_covered,
            (int) $row->points_dropped,
            (int) $row->expired_back_unused,
            $row->redemption_cap_percent === null ? null : (int) $row->redemption_cap_percent,
        );
    }
}
