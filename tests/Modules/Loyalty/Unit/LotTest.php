<?php

declare(strict_types=1);

use Modules\Loyalty\Domain\Model\Lot;

/*
| A lot is live while points are left and its date is still ahead (loyalty.md §1.3): a date exactly
| now has passed.
*/

function loyaltyLot(int $left, string $expiresAt): Lot
{
    return new Lot(1, '01hzx8k6v7q2m3n4p5r6s7t8v9', 100, $left, new DateTimeImmutable($expiresAt), null);
}

it('is live while points are left and the date is ahead', function () {
    $now = new DateTimeImmutable('2026-06-01 12:00:00+00:00');

    expect(loyaltyLot(1, '2026-06-01 12:00:01+00:00')->isLiveAt($now))->toBeTrue()
        ->and(loyaltyLot(100, '2027-01-01 00:00:00+00:00')->isLiveAt($now))->toBeTrue()
        // Exactly now: passed.
        ->and(loyaltyLot(100, '2026-06-01 12:00:00+00:00')->isLiveAt($now))->toBeFalse()
        ->and(loyaltyLot(100, '2026-05-31 12:00:00+00:00')->isLiveAt($now))->toBeFalse()
        // Nothing left: not live, however far its date.
        ->and(loyaltyLot(0, '2027-01-01 00:00:00+00:00')->isLiveAt($now))->toBeFalse();
});

it('compares moments, not the clock faces of two timezones', function () {
    // 02:30 in Riyadh is 23:30 UTC the day before: a lot dying at midnight UTC still lives.
    $lot = loyaltyLot(10, '2026-01-01 00:00:00+00:00');

    expect($lot->isLiveAt(new DateTimeImmutable('2026-01-01 02:30:00', new DateTimeZone('Asia/Riyadh'))))->toBeTrue()
        ->and($lot->isLiveAt(new DateTimeImmutable('2026-01-01 03:30:00', new DateTimeZone('Asia/Riyadh'))))->toBeFalse();
});
