<?php

declare(strict_types=1);

use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Model\Settlement\CoverSource;
use Modules\Loyalty\Domain\Model\Settlement\OrderPointsFacts;
use Modules\Loyalty\Domain\Model\Settlement\SettlementCover;
use Modules\Loyalty\Domain\Service\SettlementTargets;
use Modules\Loyalty\Public\Enums\EntryKind;
use Tests\Modules\Loyalty\Support\PointsBook;

/*
| Settling cancellations, returns and staff edits (loyalty.md §1.3, §1.8, §8 "Cancellations and
| returns"), on the pure rules: give back through each lot's covers, take back the order's own points
| first, cover the rest or drop it; by running totals. Every case also checks the ledger's sum stays
| the lots' points and never goes below 0.
*/

function settlementBook(string $now = '2026-01-15 12:00:00+00:00'): PointsBook
{
    return new PointsBook(new DateTimeImmutable($now));
}

/** The ledger's sum is every lot's points left, and the balance never goes below 0. */
function settlementBalanced(PointsBook $book): void
{
    $left = array_sum(array_map(static fn ($lot): int => $lot->pointsLeft, $book->lots));

    expect($book->ledger)->toBe($left)
        ->and($book->balance())->toBeGreaterThanOrEqual(0);

    foreach ($book->orders as $facts) {
        expect($facts->pointsGivenBack)->toBeLessThanOrEqual($facts->pointsRedeemed)
            ->and($facts->pointsBackThroughCovers)->toBeLessThanOrEqual($facts->pointsGivenBack)
            ->and($facts->takenBack())->toBeLessThanOrEqual($facts->pointsEarned)
            ->and($facts->expiredBackUnused)->toBeGreaterThanOrEqual(0);
    }
}

/** The owner's example (§1.8): 300 expiring 1 March paid A; A earned 200, which paid B. */
function settlementOwnersExample(): PointsBook
{
    $book = settlementBook();
    $book->lot(300, '2026-03-01 00:00:00+00:00');
    $book->redeem('A', 300, 300);
    $book->deliver('A', 50_000, 200, '2027-01-15 12:00:00+00:00');
    $book->redeem('B', 200, 200);
    $book->at('2026-04-10 12:00:00+00:00');

    return $book;
}

describe('the owner\'s example', function () {
    it('returns A: the 300 come back expired and cover the 200 out with B; the balance does not move', function () {
        $book = settlementOwnersExample();

        $settlement = $book->return('A', 50_000, 300);

        $covers = array_map(static fn (SettlementCover $cover): array => [$cover->source, $cover->points], $settlement->newCovers());

        expect($book->balance())->toBe(0)
            ->and($covers)->toBe([[CoverSource::ExpiredPoints, 200]])
            // The other 100 stay expired, kept for a later part of A.
            ->and($book->orders['A']->expiredBackUnused)->toBe(100);
        settlementBalanced($book);
    });

    it('then cancels B: its 200 come back to A\'s lot, undo the cover, and go nowhere — the same end as B first', function () {
        $book = settlementOwnersExample();
        $book->return('A', 50_000, 300);

        $book->cancel('B');

        $first = settlementOwnersExample();
        $first->cancel('B');
        $first->return('A', 50_000, 300);

        expect($book->balance())->toBe(0)
            ->and($first->balance())->toBe(0)
            ->and($book->orders['B']->pointsBackThroughCovers)->toBe(200);
        settlementBalanced($book);
        settlementBalanced($first);
    });
});

describe('with other points', function () {
    /** A earned 200, spent on B; the customer also has 150 in L2. */
    function settlementWithOtherPoints(): PointsBook
    {
        $book = settlementBook();
        $book->deliver('A', 20_000, 200, '2027-01-15 12:00:00+00:00');
        $book->redeem('B', 200, 200);
        $book->lot(150, '2026-12-01 00:00:00+00:00');

        return $book;
    }

    it('covers A\'s 200 with L2\'s 150 and drops 50: balance 0; B cancelled then gives L2 its 150 back, with its date', function () {
        $book = settlementWithOtherPoints();

        $book->return('A', 20_000, 0);

        expect($book->balance())->toBe(0)
            ->and($book->orders['A']->pointsCovered)->toBe(150)
            ->and($book->orders['A']->pointsDropped)->toBe(50);

        $book->cancel('B');

        $l2 = array_values(array_filter($book->lots, static fn ($lot): bool => $lot->orderId === null))[0];

        expect($book->balance())->toBe(150)
            ->and($l2->pointsLeft)->toBe(150)
            ->and($l2->expiresAt->format('Y-m-d'))->toBe('2026-12-01');
        settlementBalanced($book);
    });

    it('ends at 150 the other way round too: B cancelled first, then A returned', function () {
        $book = settlementWithOtherPoints();

        $book->cancel('B');
        $book->return('A', 20_000, 0);

        expect($book->balance())->toBe(150);
        settlementBalanced($book);
    });

    it('lets a third order settled in between change the end (§1.3, answer 15): 100 one way, 50 the other', function () {
        $returnedFirst = settlementWithOtherPoints();
        $returnedFirst->deliver('B', 0, 0, '2027-01-15 12:00:00+00:00');
        $returnedFirst->return('A', 20_000, 0);
        $returnedFirst->return('B', 0, 100);

        $halfFirst = settlementWithOtherPoints();
        $halfFirst->deliver('B', 0, 0, '2027-01-15 12:00:00+00:00');
        $halfFirst->return('B', 0, 100);
        $halfFirst->return('A', 20_000, 0);

        expect($returnedFirst->balance())->toBe(100)
            ->and($halfFirst->balance())->toBe(50);
        settlementBalanced($returnedFirst);
        settlementBalanced($halfFirst);
    });
});

it('takes the order\'s own unspent points first: used 300 since expired, earned 200 unspent, returned — 0 (answer 16)', function () {
    $book = settlementBook();
    $book->lot(300, '2026-03-01 00:00:00+00:00');
    $book->redeem('A', 300, 300);
    $book->deliver('A', 50_000, 200, '2027-01-15 12:00:00+00:00');
    $book->at('2026-04-10 12:00:00+00:00');

    $book->return('A', 50_000, 300);

    expect($book->balance())->toBe(0)
        ->and($book->orders['A']->pointsReversed)->toBe(200)
        ->and($book->orders['A']->pointsCovered)->toBe(0);
    settlementBalanced($book);
});

it('counts earned points that expired unused, or an admin removed, as taken back — never twice', function (bool $byHand) {
    $book = settlementBook();
    $book->deliver('A', 20_000, 200, $byHand ? '2027-01-15 12:00:00+00:00' : '2026-02-01 00:00:00+00:00');

    if ($byHand) {
        $book->deduct(200);
    }

    $book->at('2026-03-01 12:00:00+00:00');
    $book->lot(500, '2027-01-01 00:00:00+00:00');

    $book->return('A', 10_000, 0);
    $book->return('A', 10_000, 0);

    expect($book->orders['A']->pointsCountedGone)->toBe(200)
        ->and($book->orders['A']->pointsCovered)->toBe(0)
        // The other 500 are never touched for points already gone.
        ->and($book->balance())->toBe(500);
    settlementBalanced($book);
})->with(['expired unused' => false, 'removed by hand' => true]);

it('counts points gone by hand once across parts: what is left out with others is still covered', function () {
    $book = settlementBook();
    $book->deliver('A', 20_000, 200, '2027-01-15 12:00:00+00:00');
    $book->deduct(100);
    $book->redeem('B', 100, 100);

    $book->return('A', 10_000, 0);
    $book->return('A', 10_000, 0);

    expect($book->orders['A']->pointsCountedGone)->toBe(100)
        ->and($book->orders['A']->pointsDropped)->toBe(100);
    settlementBalanced($book);
});

it('covers with this settlement\'s given-back points before the customer\'s other lots', function () {
    $book = settlementBook();
    $giving = $book->lot(100, '2026-12-01 00:00:00+00:00');
    $book->redeem('A', 100, 100);
    $book->deliver('A', 10_000, 100, '2027-01-15 12:00:00+00:00');
    $book->redeem('B', 100, 100);
    // A lot that expires sooner, made after A was placed: still not the first to cover.
    $sooner = $book->lot(100, '2026-06-01 00:00:00+00:00');

    $book->return('A', 10_000, 100);

    expect($book->lots[$sooner]->pointsLeft)->toBe(100)
        ->and($book->lots[$giving]->pointsLeft)->toBe(0);
    settlementBalanced($book);
});

it('covers from the other lot that expires first, whatever their order of making', function () {
    $book = settlementBook();
    $book->deliver('A', 10_000, 100, '2027-01-15 12:00:00+00:00');
    $book->redeem('B', 100, 100);
    $later = $book->lot(100, '2026-12-01 00:00:00+00:00');
    $sooner = $book->lot(100, '2026-06-01 00:00:00+00:00');

    $book->return('A', 10_000, 0);

    expect([$book->lots[$sooner]->pointsLeft, $book->lots[$later]->pointsLeft])->toBe([0, 100]);
});

it('undoes a lot\'s lot covers before its expired-point covers, though they are younger, and gives the covering take its points back', function () {
    $book = settlementBook();
    $book->lot(100, '2026-03-01 00:00:00+00:00');
    $book->redeem('A', 100, 100);
    $book->deliver('A', 20_000, 200, '2027-01-15 12:00:00+00:00');
    $book->redeem('B', 200, 200);
    $book->deliver('B', 0, 0, '2027-01-15 12:00:00+00:00');
    $l2 = $book->lot(150, '2026-12-01 00:00:00+00:00');
    $book->at('2026-04-10 12:00:00+00:00');
    // A in two parts: its expired 100 cover the first 100 (older), L2 the next 100 (younger).
    $book->return('A', 10_000, 100);
    $second = $book->return('A', 10_000, 0);
    $coverTake = $second->newTakes()[0];

    // Half of B back: the lot cover is undone first — its 100 go back to L2.
    $book->return('B', 0, 100);

    expect($book->lots[$l2]->pointsLeft)->toBe(150)
        ->and($coverTake->back)->toBe(100);
    settlementBalanced($book);
});

it('counts points that came back expired to the earned lot as gone: two orders end alike either way', function () {
    $cancelledFirst = settlementBook();
    $returnedFirst = settlementBook();

    foreach ([$cancelledFirst, $returnedFirst] as $book) {
        $book->deliver('A', 20_000, 200, '2026-02-01 00:00:00+00:00');
        $book->redeem('B', 200, 200);
        $book->lot(300, '2027-01-01 00:00:00+00:00');
        $book->at('2026-03-01 12:00:00+00:00');
    }

    $cancelled = $cancelledFirst->cancel('B');
    $cancelledFirst->return('A', 20_000, 0);
    $returnedFirst->return('A', 20_000, 0);
    $returnedFirst->cancel('B');

    $history = array_map(static fn ($move): array => [$move->kind->value, $move->points], $cancelled->movements());

    // The history shows both: returned to the lot, and expired at once.
    expect($history)->toBe([['REDEMPTION_RETURNED', 200], ['EXPIRED', -200]])
        ->and($cancelledFirst->balance())->toBe(300)
        ->and($returnedFirst->balance())->toBe(300)
        // B's own given-back points, on the lot B took them from: B's to cover with, were B delivered.
        ->and($cancelledFirst->orders['B']->expiredBackUnused)->toBe(200);
    settlementBalanced($cancelledFirst);
    settlementBalanced($returnedFirst);
});

it('does not count points passed back to a lot that covered as the order\'s own, live or expired', function () {
    $book = settlementBook();
    $book->deliver('A', 20_000, 200, '2027-01-15 12:00:00+00:00');
    $book->redeem('B', 200, 200);
    $book->lot(150, '2026-03-01 00:00:00+00:00');
    $book->return('A', 20_000, 0);
    $book->at('2026-04-01 12:00:00+00:00');

    $book->cancel('B');

    // L2's 150 came back to L2, dead by now: L2's own points, returned and expired — not B's.
    expect($book->orders['B']->expiredBackUnused)->toBe(0)
        ->and($book->orders['B']->pointsBackThroughCovers)->toBe(200);
    settlementBalanced($book);
});

it('drops what nothing covers, recorded on the order, and never goes below 0', function () {
    $book = settlementBook();
    $book->deliver('A', 30_000, 300, '2027-01-15 12:00:00+00:00');
    $book->redeem('B', 300, 300);

    $book->return('A', 30_000, 0);

    expect($book->orders['A']->pointsDropped)->toBe(300)
        ->and($book->balance())->toBe(0);
    settlementBalanced($book);
});

it('lands a return in three parts exactly on the order\'s points, every total within its rules after each part', function () {
    $book = settlementBook();
    $book->lot(500, '2027-01-01 00:00:00+00:00');
    $book->redeem('A', 500, 997);
    $book->deliver('A', 999, 1000, '2027-01-15 12:00:00+00:00');
    $book->redeem('B', 400, 400);

    foreach ([[333, 300], [333, 300], [333, 397]] as [$goods, $discount]) {
        $book->return('A', $goods, $discount);
        settlementBalanced($book);
    }

    expect($book->orders['A']->pointsGivenBack)->toBe(500)
        ->and($book->orders['A']->takenBack())->toBe(1000);
});

it('settles a cancellation twice once: the second moves nothing', function () {
    $book = settlementBook();
    $book->lot(300, '2027-01-01 00:00:00+00:00');
    $book->redeem('B', 200, 200);
    $book->cancel('B');

    $again = $book->cancel('B');

    expect($again->movements())->toBe([])
        ->and($book->balance())->toBe(300);
});

it('settles an order with a goods total of 0, or no points discount, without dividing by 0', function () {
    $book = settlementBook();
    $book->deliver('A', 0, 0, '2027-01-15 12:00:00+00:00');

    $settlement = $book->return('A', 0, 0);

    expect($settlement->movements())->toBe([])
        ->and(SettlementTargets::afterEdit(new OrderPointsFacts(0, 0, 0, null), 50, 10_000, 10_000))->toBe([0, 0]);
});

it('refuses returned amounts past the order\'s', function () {
    $book = settlementBook();
    $book->lot(300, '2027-01-01 00:00:00+00:00');
    $book->redeem('A', 100, 100);
    $book->deliver('A', 1_000, 10, '2027-01-15 12:00:00+00:00');
    $book->return('A', 600, 50);

    expect(fn () => $book->return('A', 401, 0))->toThrow(InvalidPointsAttribute::class)
        ->and(fn () => $book->return('A', 0, 51))->toThrow(InvalidPointsAttribute::class)
        ->and(fn () => $book->return('A', -1, 0))->toThrow(InvalidPointsAttribute::class);
});

describe('a staff edit before shipping', function () {
    it('gives back the points above the new discount, with their old dates, once per edit; takes nothing back', function () {
        $book = settlementBook();
        $book->lot(100, '2026-06-01 00:00:00+00:00');
        $book->lot(300, '2027-01-01 00:00:00+00:00');
        $book->redeem('A', 400, 400);

        $settlement = $book->edit('A', 150);

        // The points spent first come back first: 100 to June's lot, 150 to January's.
        expect($book->balance())->toBe(250)
            ->and($book->orders['A']->pointsGivenBack)->toBe(250)
            ->and($settlement->total(EntryKind::EarningReversed))->toBe(0)
            ->and(array_map(static fn ($lot): int => $lot->pointsLeft, array_values($book->lots)))->toBe([100, 150]);
        settlementBalanced($book);
    });

    it('refuses a new discount above the old', function () {
        $book = settlementBook();
        $book->lot(300, '2027-01-01 00:00:00+00:00');
        $book->redeem('A', 200, 200);
        $book->edit('A', 150);

        expect(fn () => $book->edit('A', 151))->toThrow(InvalidPointsAttribute::class);
    });

    it('answers the least of the discount now, the cap on the edited subtotal by the order\'s own percent, and the room', function () {
        // 400 points for 4.00 off, placed at 50%; nothing given back yet.
        $facts = new OrderPointsFacts(400, 400, 0, null);

        expect(SettlementTargets::afterEdit($facts, 50, 10_000, 10_000))->toBe([400, 400])
            // The cap: 50% of 5.00 is 2.50 — half the points come back.
            ->and(SettlementTargets::afterEdit($facts, 50, 500, 10_000))->toBe([250, 250])
            // The room the coupon left.
            ->and(SettlementTargets::afterEdit($facts, 50, 10_000, 100))->toBe([100, 100])
            // Its own percent, though the store's changed since: 10% of 10.00.
            ->and(SettlementTargets::afterEdit($facts, 10, 1_000, 10_000))->toBe([100, 100]);
    });

    it('lets a full return later still land exactly on the order\'s points', function () {
        $book = settlementBook();
        $book->lot(500, '2027-01-01 00:00:00+00:00');
        $book->redeem('A', 400, 400);
        $book->edit('A', 300);
        $book->deliver('A', 10_000, 100, '2027-01-15 12:00:00+00:00');

        $book->return('A', 10_000, 300);

        expect($book->orders['A']->pointsGivenBack)->toBe(400)
            ->and($book->orders['A']->takenBack())->toBe(100)
            ->and($book->balance())->toBe(500);
        settlementBalanced($book);
    });
});

it('never goes below 0 and keeps the ledger whole through random histories', function (int $seed) {
    mt_srand($seed);
    $book = settlementBook();
    $book->lot(mt_rand(0, 400) + 1, '2026-03-01 00:00:00+00:00');
    $book->lot(mt_rand(0, 400) + 1, '2026-09-01 00:00:00+00:00');
    $orders = [];

    foreach (range(1, 6) as $n) {
        $order = "O{$n}";
        $spend = min($book->balance(), mt_rand(0, 250));

        if ($spend > 0) {
            $book->redeem($order, $spend, $spend);
        }

        $book->deliver($order, 10_000, mt_rand(0, 300), '2026-0'.mt_rand(4, 9).'-01 00:00:00+00:00');
        $orders[] = $order;
        $book->at('2026-0'.min(9, 1 + $n).'-15 12:00:00+00:00');
    }

    shuffle($orders);

    // Some never delivered: cancelled instead, after an admin removed a little.
    $book->deduct(min($book->balance(), mt_rand(0, 50)));
    settlementBalanced($book);

    foreach (array_splice($orders, 0, 2) as $order) {
        $book->orders[$order] = new OrderPointsFacts($book->orders[$order]->pointsRedeemed, $book->orders[$order]->discountMinor, 0, null);
        $book->cancel($order);
        settlementBalanced($book);
    }

    foreach ($orders as $order) {
        $facts = $book->orders[$order];
        $book->return($order, 5_000, intdiv($facts->discountMinor, 2));
        settlementBalanced($book);
        $book->return($order, 5_000, $facts->discountMinor - $facts->returnedDiscountMinor);
        settlementBalanced($book);

        expect($facts->pointsGivenBack)->toBe($facts->pointsRedeemed)
            ->and($facts->takenBack())->toBe($facts->pointsEarned);
    }
})->with(range(1, 40));
