<?php

declare(strict_types=1);

use Modules\Platform\Public\Dto\HomeChange;
use Modules\Platform\Public\Dto\HomeRange;
use Modules\Platform\Public\Dto\HomeSplit;
use Modules\Platform\Public\Enums\HomePeriod;

/*
| The admin home's periods (dashboard.md §3.3), each against the period before it of the same length, in a store's
| own clock; the change against it (§3.4); a split's shares (§2 D, I).
*/

/** A moment in the zone, read back as the zone's wall clock. */
function homePeriodRange(HomePeriod $period, string $now, string $zone = 'Asia/Riyadh'): HomeRange
{
    return $period->range(new DateTimeImmutable($now, new DateTimeZone($zone)), new DateTimeZone($zone));
}

/** @return list<string> the four moments of a range, written in its zone */
function homePeriodMoments(HomeRange $range): array
{
    return array_map(static fn (DateTimeImmutable $at): string => $at->format('Y-m-d H:i'), [$range->from, $range->to, $range->beforeFrom, $range->beforeTo]);
}

it('reads each period up to now, against the one before it to the same point', function (HomePeriod $period, string $now, array $expected) {
    expect(homePeriodMoments(homePeriodRange($period, $now)))->toBe($expected);
})->with([
    'today: midnight to now, against yesterday to the same time' => [HomePeriod::Today, '2026-11-02 15:30', ['2026-11-02 00:00', '2026-11-02 15:30', '2026-11-01 00:00', '2026-11-01 15:30']],
    '7 days: today and the 6 before, against the 7 before those' => [HomePeriod::SevenDays, '2026-11-02 15:30', ['2026-10-27 00:00', '2026-11-02 15:30', '2026-10-20 00:00', '2026-10-26 15:30']],
    'this month: the 1st to now, against the month before to the same day' => [HomePeriod::Month, '2026-11-02 15:30', ['2026-11-01 00:00', '2026-11-02 15:30', '2026-10-01 00:00', '2026-10-02 15:30']],
    'this month on the 31st: against the last day of a shorter month' => [HomePeriod::Month, '2026-03-31 09:00', ['2026-03-01 00:00', '2026-03-31 09:00', '2026-02-01 00:00', '2026-02-28 09:00']],
    'this month on the 31st, in a leap year' => [HomePeriod::Month, '2028-03-31 09:00', ['2028-03-01 00:00', '2028-03-31 09:00', '2028-02-01 00:00', '2028-02-29 09:00']],
    'this month in January: against December of the year before' => [HomePeriod::Month, '2027-01-15 08:00', ['2027-01-01 00:00', '2027-01-15 08:00', '2026-12-01 00:00', '2026-12-15 08:00']],
    '3 months: this month and the two before, across a new year' => [HomePeriod::ThreeMonths, '2027-02-10 10:00', ['2026-12-01 00:00', '2027-02-10 10:00', '2026-09-01 00:00', '2026-11-10 10:00']],
    '3 months on the 31st: the same point three months back, clamped' => [HomePeriod::ThreeMonths, '2026-05-31 10:00', ['2026-03-01 00:00', '2026-05-31 10:00', '2025-12-01 00:00', '2026-02-28 10:00']],
    'year: 1 January to now, against the year before' => [HomePeriod::Year, '2026-11-02 15:30', ['2026-01-01 00:00', '2026-11-02 15:30', '2025-01-01 00:00', '2025-11-02 15:30']],
    'year on 29 February: against the 28th of the year before' => [HomePeriod::Year, '2028-02-29 12:00', ['2028-01-01 00:00', '2028-02-29 12:00', '2027-01-01 00:00', '2027-02-28 12:00']],
]);

it('works the period out in the store\'s own clock, whatever zone the moment comes in', function () {
    // 21:30 UTC on 1 November is already 2 November in Riyadh (UTC+3), and still 1 November in Cairo (UTC+2).
    $at = new DateTimeImmutable('2026-11-01 21:30', new DateTimeZone('UTC'));

    expect(homePeriodMoments(HomePeriod::Today->range($at, new DateTimeZone('Asia/Riyadh'))))->toBe(['2026-11-02 00:00', '2026-11-02 00:30', '2026-11-01 00:00', '2026-11-01 00:30'])
        ->and(homePeriodMoments(HomePeriod::Today->range($at, new DateTimeZone('Africa/Cairo'))))->toBe(['2026-11-01 00:00', '2026-11-01 23:30', '2026-10-31 00:00', '2026-10-31 23:30']);
});

it('opens on This Month', function () {
    expect(HomePeriod::OPENS_ON)->toBe(HomePeriod::Month)
        ->and(HomePeriod::tryFrom('3months'))->toBe(HomePeriod::ThreeMonths);
});

it('names a point for each day the period touches, a month for the Year, and lines the period before up with it', function () {
    $month = homePeriodRange(HomePeriod::Month, '2026-03-31 09:00');
    $year = homePeriodRange(HomePeriod::Year, '2026-03-02 09:00');
    $week = homePeriodRange(HomePeriod::SevenDays, '2026-11-02 15:30');

    expect($month->points())->toHaveCount(31)
        ->and($month->points()[0])->toBe('2026-03-01')
        ->and($month->beforePoints())->toHaveCount(28)
        ->and($week->points())->toBe(['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'])
        ->and($week->beforePoints())->toHaveCount(7)
        ->and($year->byMonth)->toBeTrue()
        ->and($year->points())->toBe(['2026-01', '2026-02', '2026-03'])
        ->and($year->pointOf('2026-02-14'))->toBe('2026-02')
        ->and($month->pointOf('2026-03-14'))->toBe('2026-03-14')
        // At midnight today has begun but holds no moment yet: no point.
        ->and(homePeriodRange(HomePeriod::Today, '2026-11-02 00:00')->points())->toBe([]);
});

it('works the change out against the period before: up, down, New from nothing, none from nothing to nothing', function (int|float $now, int|float $before, ?float $percent, bool $shown) {
    $change = HomeChange::of($now, $before);

    expect($change === null)->toBe(! $shown);

    if ($change !== null) {
        expect($change->percent)->toBe($percent);
    }
})->with([
    'up' => [150, 100, 50.0, true],
    'down, to one decimal' => [2, 3, -33.3, true],
    'the same' => [7, 7, 0.0, true],
    'new: nothing before, something now' => [5, 0, null, true],
    'nothing either time: no change shown' => [0, 0, null, false],
    'down to nothing' => [0, 40, -100.0, true],
]);

it('shares a whole among its parts to one decimal, and gives every part nothing of a whole of nothing', function () {
    expect(HomeSplit::shares([1, 2]))->toBe([33.3, 66.7])
        ->and(HomeSplit::shares([0, 0]))->toBe([0.0, 0.0])
        ->and(HomeSplit::shares([]))->toBe([]);
});
