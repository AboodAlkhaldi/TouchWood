<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Service;

use DateTimeImmutable;
use LogicException;
use Modules\Loyalty\Domain\Model\Settlement\CoverSource;
use Modules\Loyalty\Domain\Model\Settlement\Movement;
use Modules\Loyalty\Domain\Model\Settlement\OrderPointsFacts;
use Modules\Loyalty\Domain\Model\Settlement\SettlementCover;
use Modules\Loyalty\Domain\Model\Settlement\SettlementLot;
use Modules\Loyalty\Domain\Model\Settlement\SettlementTake;
use Modules\Loyalty\Public\Enums\EntryKind;

/**
 * One settlement of an order's points (loyalty.md §1.8), worked out on what the account holds now —
 * pure: it reads a snapshot and answers the moves; the repository writes them.
 *
 * **Give back** first, from the order's takes in the order they were spent: each point goes back to
 * its take's lot, which first undoes that lot's covers (lot covers oldest first, back to the lots
 * that covered — the same way, their own covers first; then covers by expired points and drops,
 * which go nowhere); the rest lands on the lot with its date, expired at once if that has passed.
 *
 * **Take back** then, from the order's earned lot: a. its live points; b. what left it by expiry or
 * by hand, counted as taken once; c. the rest covered by 1. this order's given-back points that came
 * back expired, 2. this settlement's other given-back points where they landed, 3. the customer's
 * other live lots, the one expiring first first, 4. nothing — dropped. Never below 0 (§1.4).
 */
final class Settlement
{
    /** @var list<Movement> */
    private array $movements = [];

    /** @var list<SettlementTake> */
    private array $newTakes = [];

    /** @var list<SettlementCover> */
    private array $newCovers = [];

    /** @var array<int, int> existing take id → points come back */
    private array $takesBack = [];

    /** @var array<int, int> existing cover id → points undone */
    private array $coversUndone = [];

    /** @var array<int, int> lot id → given-back points that landed live in this settlement */
    private array $landed = [];

    /**
     * @param  list<SettlementTake>  $orderTakes  the order's REDEMPTION takes, in the order spent
     * @param  array<int, SettlementLot>  $lots  every lot of the account it may touch, by id
     * @param  int  $goneFromEarnedLot  points that left the earned lot by expiry or by hand
     */
    public function __construct(
        public readonly OrderPointsFacts $facts,
        private readonly array $orderTakes,
        private readonly array $lots,
        private readonly ?int $earnedLotId,
        private readonly int $goneFromEarnedLot,
        private readonly DateTimeImmutable $now,
    ) {}

    public function giveBack(int $points): void
    {
        $left = $points;

        foreach ($this->orderTakes as $take) {
            if ($left === 0) {
                break;
            }

            $back = min($left, $take->out());

            if ($back === 0) {
                continue;
            }

            $this->comeBack($take, $back);
            $left -= $back;
            $this->facts->pointsGivenBack += $back;
            $this->facts->pointsBackThroughCovers += $this->toLot($take->lotId, $back, firstLot: true);
        }

        if ($left > 0) {
            throw new LogicException('A settlement gave back more points than the order took.');
        }
    }

    public function takeBack(int $points): void
    {
        if ($points === 0) {
            return;
        }

        $earned = $this->lot($this->earnedLotId ?? throw new LogicException('Points taken back from an order that earned none.'));

        // a. What is still in the order's own lot, if it is live.
        $own = $earned->isLiveAt($this->now) ? min($points, $earned->pointsLeft) : 0;
        $this->off($earned, $own);
        $this->facts->pointsReversed += $own;
        $left = $points - $own;

        // b. What left it by expiry or by hand counts as taken back already — never twice.
        $gone = min($left, $this->goneFromEarnedLot - $this->facts->pointsCountedGone);
        $this->facts->pointsCountedGone += max(0, $gone);
        $left -= max(0, $gone);

        // c1. This order's given-back points that came back expired, not yet used to cover.
        $expired = min($left, $this->facts->expiredBackUnused);
        $this->cover($earned, CoverSource::ExpiredPoints, null, $expired);
        $this->facts->expiredBackUnused -= $expired;
        $this->facts->pointsCovered += $expired;
        $left -= $expired;

        // c2. This settlement's other given-back points, where they landed; c3. the customer's other
        // live lots — each the one expiring first first.
        foreach ([$this->landedLots(), $this->otherLiveLots()] as $lots) {
            foreach ($lots as [$lot, $most]) {
                $taken = min($left, $most, $lot->pointsLeft);

                if ($taken === 0) {
                    continue;
                }

                $this->off($lot, $taken);
                $take = new SettlementTake(null, $lot->id, $taken);
                $this->newTakes[] = $take;
                $this->cover($earned, CoverSource::Lot, $take, $taken);
                $this->facts->pointsCovered += $taken;
                $left -= $taken;
            }
        }

        // c4. Nothing left to cover it: dropped (§1.4).
        $this->cover($earned, CoverSource::Dropped, null, $left);
        $this->facts->pointsDropped += $left;
    }

    /** @return list<Movement> */
    public function movements(): array
    {
        return $this->movements;
    }

    /** @return list<SettlementTake> the COVER takes this settlement made */
    public function newTakes(): array
    {
        return $this->newTakes;
    }

    /** @return list<SettlementCover> */
    public function newCovers(): array
    {
        return $this->newCovers;
    }

    /** @return array<int, int> existing take id → points come back */
    public function takesBack(): array
    {
        return $this->takesBack;
    }

    /** @return array<int, int> existing cover id → points undone */
    public function coversUndone(): array
    {
        return $this->coversUndone;
    }

    /** The points a kind of entry moves in all: an entry is written only when it is not 0. */
    public function total(EntryKind $kind): int
    {
        return array_sum(array_map(
            static fn (Movement $move): int => $move->points,
            array_filter($this->movements, static fn (Movement $move): bool => $move->kind === $kind),
        ));
    }

    /**
     * Points coming back to a lot: its covers first, the rest onto it. Answers the points its covers
     * took, counted at the first lot only, where the order's take was.
     */
    private function toLot(int $lotId, int $points, bool $firstLot = false): int
    {
        $lot = $this->lot($lotId);
        $throughCovers = 0;

        foreach ($lot->coversToUndo() as $cover) {
            $undo = min($points, $cover->open());

            if ($undo === 0) {
                continue;
            }

            $cover->undone += $undo;

            if ($cover->id !== null) {
                $this->coversUndone[$cover->id] = ($this->coversUndone[$cover->id] ?? 0) + $undo;
            }

            $points -= $undo;
            $throughCovers += $undo;

            // A cover taken from a lot is a take from it, and comes back the same way (§1.3).
            if ($cover->source === CoverSource::Lot && $cover->sourceTake !== null) {
                $this->comeBack($cover->sourceTake, $undo);
                $this->toLot($cover->sourceTake->lotId, $undo);
            }

            if ($points === 0) {
                break;
            }
        }

        if ($points > 0) {
            $this->land($lot, $points);
        }

        return $firstLot ? $throughCovers : 0;
    }

    private function land(SettlementLot $lot, int $points): void
    {
        $this->movements[] = new Movement($lot->id, $points, EntryKind::RedemptionReturned);

        if ($lot->isLiveAt($this->now)) {
            $lot->pointsLeft += $points;
            $this->landed[$lot->id] = ($this->landed[$lot->id] ?? 0) + $points;

            return;
        }

        // Its date has passed: returned and expired at once (§1.3), kept to cover what is taken back.
        $this->movements[] = new Movement($lot->id, -$points, EntryKind::Expired);
        $this->facts->expiredBackUnused += $points;
    }

    private function off(SettlementLot $lot, int $points): void
    {
        if ($points === 0) {
            return;
        }

        $lot->pointsLeft -= $points;
        $this->movements[] = new Movement($lot->id, -$points, EntryKind::EarningReversed);
    }

    private function cover(SettlementLot $earned, CoverSource $source, ?SettlementTake $take, int $points): void
    {
        if ($points === 0) {
            return;
        }

        $cover = new SettlementCover(null, $earned->id, $source, $take, $points);
        $earned->covers[] = $cover;
        $this->newCovers[] = $cover;
    }

    private function comeBack(SettlementTake $take, int $points): void
    {
        $take->back += $points;

        if ($take->id !== null) {
            $this->takesBack[$take->id] = ($this->takesBack[$take->id] ?? 0) + $points;
        }
    }

    /**
     * Lots where this settlement's given-back points landed and are live, the one expiring first first.
     *
     * @return list<array{SettlementLot, int}>
     */
    private function landedLots(): array
    {
        $lots = [];

        foreach ($this->landed as $id => $points) {
            if ($id !== $this->earnedLotId) {
                $lots[] = [$this->lot($id), $points];
            }
        }

        return self::bySpending($lots);
    }

    /**
     * The customer's other live lots, the one expiring first first.
     *
     * @return list<array{SettlementLot, int}>
     */
    private function otherLiveLots(): array
    {
        $lots = [];

        foreach ($this->lots as $lot) {
            if ($lot->id !== $this->earnedLotId && $lot->pointsLeft > 0 && $lot->isLiveAt($this->now)) {
                $lots[] = [$lot, $lot->pointsLeft];
            }
        }

        return self::bySpending($lots);
    }

    /**
     * @param  list<array{SettlementLot, int}>  $lots
     * @return list<array{SettlementLot, int}>
     */
    private static function bySpending(array $lots): array
    {
        usort($lots, static fn (array $a, array $b): int => [$a[0]->expiresAt, $a[0]->id] <=> [$b[0]->expiresAt, $b[0]->id]);

        return $lots;
    }

    private function lot(int $id): SettlementLot
    {
        return $this->lots[$id] ?? throw new LogicException("Lot {$id} is not in the settlement's snapshot.");
    }
}
