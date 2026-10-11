<?php

declare(strict_types=1);

use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Service\PointsArithmetic as Points;

/*
| The programme's arithmetic (loyalty.md §1.5, §8): whole numbers in the currency's smallest unit,
| floors and ceilings exactly where the spec puts them.
*/

describe('earning', function () {
    it('earns on the whole units spent, never on a fraction', function () {
        // 1,234.56 SAR at 1 point per SAR: 1,234 points (§8).
        expect(Points::earned(123_456, 1, 2))->toBe(1_234)
            // 0.99 SAR earns nothing — the delivery is still recorded, by the caller.
            ->and(Points::earned(99, 1, 2))->toBe(0)
            ->and(Points::earned(100, 1, 2))->toBe(1)
            ->and(Points::earned(123_456, 5, 2))->toBe(6_172);
    });

    it('reads the currency\'s own exponent: a 3-decimal currency counts thousandths', function () {
        // 123.456 units of a 3-decimal currency at 1 a unit: 123 points; read at 2 decimals it
        // would be 1,234 — ten times too many.
        expect(Points::earned(123_456, 1, 3))->toBe(123)
            ->and(Points::earned(5, 1, 0))->toBe(5);
    });
});

describe('what points are worth, and what a discount costs', function () {
    it('makes 100 points 1 SAR off at the default rate', function () {
        expect(Points::worth(100, 100, 2))->toBe(100)
            ->and(Points::pointsCharged(100, 100, 2))->toBe(100);
    });

    it('charges the fewest points worth the discount: 4 asked at 150 a SAR are worth 2 halalas, for 3 points', function () {
        expect(Points::worth(4, 150, 2))->toBe(2)
            ->and(Points::pointsCharged(2, 150, 2))->toBe(3)
            ->and(Points::worth(3, 150, 2))->toBe(2);
    });

    it('never charges a point that bought nothing, and never one fewer than the discount needs', function (int $pointsPerUnitOff) {
        foreach (range(1, 400) as $discount) {
            $charged = Points::pointsCharged($discount, $pointsPerUnitOff, 2);

            expect(Points::worth($charged, $pointsPerUnitOff, 2))->toBeGreaterThanOrEqual($discount)
                ->and(Points::worth($charged - 1, $pointsPerUnitOff, 2))->toBeLessThan($discount);
        }
    })->with([1, 3, 7, 100, 150, 1_000, 100_000]);
});

describe('the cap', function () {
    it('lets points pay at most the percent of the net subtotal, rounded down', function () {
        expect(Points::cap(10_000, 50))->toBe(5_000)
            ->and(Points::cap(999, 50))->toBe(499)
            ->and(Points::cap(10_000, 0))->toBe(0)
            ->and(Points::cap(10_000, 100))->toBe(10_000);
    });

    it('finds the largest count of points whose worth stays within the cap', function (int $pointsPerUnitOff) {
        foreach ([0, 1, 2, 33, 499, 5_000] as $cap) {
            $most = Points::mostPointsUnderCap($cap, $pointsPerUnitOff, 2);

            expect(Points::worth($most, $pointsPerUnitOff, 2))->toBeLessThanOrEqual($cap)
                ->and(Points::worth($most + 1, $pointsPerUnitOff, 2))->toBeGreaterThan($cap);
        }
    })->with([1, 3, 7, 100, 150, 1_000]);
});

it('never overflows on the way: a product past 64 bits still comes out right', function () {
    // 9e16 halalas at 1,000 points a unit: the product is 9e19, past PHP_INT_MAX (≈9.2e18).
    expect(Points::earned(90_000_000_000_000_000, 1_000, 2))->toBe(900_000_000_000_000_000);
});

it('refuses what the programme never allows', function (Closure $call, string $attribute) {
    try {
        $call();
    } catch (InvalidPointsAttribute $refused) {
        expect($refused->attribute)->toBe($attribute);

        return;
    }

    throw new RuntimeException('Nothing was refused.');
})->with([
    'a negative amount' => [fn () => Points::earned(-1, 1, 2), 'amount'],
    'negative points' => [fn () => Points::worth(-1, 100, 2), 'points'],
    'a rate of 0' => [fn () => Points::pointsCharged(100, 0, 2), 'rate'],
    'a percent past 100' => [fn () => Points::cap(100, 101), 'percent'],
    'a percent below 0' => [fn () => Points::cap(100, -1), 'percent'],
    'an exponent past 4' => [fn () => Points::worth(1, 100, 5), 'currency'],
]);
