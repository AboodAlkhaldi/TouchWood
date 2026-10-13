<?php

declare(strict_types=1);

namespace Tests\Modules\Loyalty\Support;

use DateTimeImmutable;
use LogicException;
use Modules\Loyalty\Domain\Model\Settlement\OrderPointsFacts;
use Modules\Loyalty\Domain\Model\Settlement\SettlementLot;
use Modules\Loyalty\Domain\Model\Settlement\SettlementTake;
use Modules\Loyalty\Domain\Service\Settlement;
use Modules\Loyalty\Domain\Service\SettlementTargets;
use Modules\Loyalty\Public\Enums\EntryKind;

/**
 * One customer's points in one store, in memory, for the settlement rules' tests: lots, orders that
 * spend from and earn into them, expiry, changes by hand — and settlements worked out by the real
 * rules on the same objects, so a sequence of them plays out as the ledger would hold it.
 */
final class PointsBook
{
    /** @var array<int, SettlementLot> */
    public array $lots = [];

    /** @var array<string, OrderPointsFacts> */
    public array $orders = [];

    /** @var array<string, list<SettlementTake>> */
    private array $takes = [];

    /** @var array<string, int> */
    private array $earnedLots = [];

    /** @var array<int, int> lot → points gone by expiry or by hand */
    private array $gone = [];

    /** The ledger's sum: every entry the settlements wrote, with the book's own changes. */
    public int $ledger = 0;

    private int $nextLot = 1;

    private int $nextTake = 1;

    public function __construct(public DateTimeImmutable $now) {}

    public function at(string $moment): self
    {
        $this->now = new DateTimeImmutable($moment);
        $this->expire();

        return $this;
    }

    /** Points added by hand, or earned by an order delivered: a lot. */
    public function lot(int $points, string $expiresAt, ?string $order = null): int
    {
        $id = $this->nextLot++;
        $this->lots[$id] = new SettlementLot($id, $points, new DateTimeImmutable($expiresAt), $order);
        $this->ledger += $points;

        return $id;
    }

    /** An order placed with points (and a discount worth them), taken from the lots expiring first. */
    public function redeem(string $order, int $points, int $discountMinor): void
    {
        $this->expire();
        $this->orders[$order] = new OrderPointsFacts($points, $discountMinor, 0, null);
        $this->takes[$order] = [];
        $left = $points;

        foreach ($this->spendable() as $lot) {
            $taken = min($left, $lot->pointsLeft);

            if ($taken > 0) {
                $lot->pointsLeft -= $taken;
                $this->takes[$order][] = new SettlementTake($this->nextTake++, $lot->id, $taken);
                $left -= $taken;
            }
        }

        if ($left > 0) {
            throw new LogicException('Not enough points to redeem.');
        }

        $this->ledger -= $points;
    }

    /** The order delivered: earned into a lot of its own (0 earns nothing, but the delivery counts). */
    public function deliver(string $order, int $goodsTotalMinor, int $earned, string $expiresAt): void
    {
        $was = $this->orders[$order] ?? new OrderPointsFacts(0, 0, 0, null);
        // The order's running totals so far (an edit before delivery) stay with it.
        $this->orders[$order] = new OrderPointsFacts(
            $was->pointsRedeemed, $was->discountMinor, $earned, $goodsTotalMinor,
            $was->returnedGoodsMinor, $was->returnedDiscountMinor, $was->pointsGivenBack, $was->pointsBackThroughCovers,
            $was->pointsReversed, $was->pointsCountedGone, $was->pointsCovered, $was->pointsDropped, $was->expiredBackUnused,
        );
        $this->takes[$order] ??= [];

        if ($earned > 0) {
            $this->earnedLots[$order] = $this->lot($earned, $expiresAt, $order);
        }
    }

    /** An admin removes points by hand, from the lots expiring first; final. */
    public function deduct(int $points): void
    {
        $this->expire();
        $left = $points;

        foreach ($this->spendable() as $lot) {
            $taken = min($left, $lot->pointsLeft);
            $lot->pointsLeft -= $taken;
            $this->gone[$lot->id] = ($this->gone[$lot->id] ?? 0) + $taken;
            $left -= $taken;
        }

        $this->ledger -= $points - $left;
    }

    public function cancel(string $order): Settlement
    {
        [$give, $take] = SettlementTargets::cancellation($this->orders[$order]);

        return $this->settle($order, $give, $take);
    }

    public function return(string $order, int $goodsShareMinor, int $discountShareMinor): Settlement
    {
        [$give, $take] = SettlementTargets::return($this->orders[$order], $goodsShareMinor, $discountShareMinor);

        return $this->settle($order, $give, $take);
    }

    public function edit(string $order, int $newDiscountMinor): Settlement
    {
        return $this->settle($order, SettlementTargets::edit($this->orders[$order], $newDiscountMinor), 0);
    }

    /** What the customer can use now: the live lots' points. */
    public function balance(): int
    {
        return array_sum(array_map(
            fn (SettlementLot $lot): int => $lot->isLiveAt($this->now) ? $lot->pointsLeft : 0,
            $this->lots,
        ));
    }

    private function settle(string $order, int $give, int $take): Settlement
    {
        $this->expire();
        $earned = $this->earnedLots[$order] ?? null;
        $settlement = new Settlement($this->orders[$order], $this->takes[$order], $this->lots, $earned, $earned === null ? 0 : ($this->gone[$earned] ?? 0), $this->now);
        $settlement->giveBack($give);
        $settlement->takeBack($take);

        // Points that came back to a dead lot expired at once: they left that lot by expiry (b).
        foreach ($settlement->movements() as $move) {
            if ($move->kind === EntryKind::Expired) {
                $this->gone[$move->lotId] = ($this->gone[$move->lotId] ?? 0) - $move->points;
            }
        }

        $this->ledger += $settlement->total(EntryKind::RedemptionReturned) + $settlement->total(EntryKind::EarningReversed) + $settlement->total(EntryKind::Expired);
        $this->expire();

        return $settlement;
    }

    /** Every write first expires dead lots (§1.10): what is left in them is gone. */
    private function expire(): void
    {
        foreach ($this->lots as $lot) {
            if (! $lot->isLiveAt($this->now) && $lot->pointsLeft > 0) {
                $this->gone[$lot->id] = ($this->gone[$lot->id] ?? 0) + $lot->pointsLeft;
                $this->ledger -= $lot->pointsLeft;
                $lot->pointsLeft = 0;
            }
        }
    }

    /** @return list<SettlementLot> */
    private function spendable(): array
    {
        $lots = array_values(array_filter($this->lots, fn (SettlementLot $lot): bool => $lot->pointsLeft > 0 && $lot->isLiveAt($this->now)));
        usort($lots, static fn (SettlementLot $a, SettlementLot $b): int => [$a->expiresAt, $a->id] <=> [$b->expiresAt, $b->id]);

        return $lots;
    }
}
