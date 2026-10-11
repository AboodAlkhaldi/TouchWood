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

    it('never charges a point that bought nothing, and never one fewer than the discount needs', function (int $pointsPerUnitOff, int $exponent) {
        foreach (range(1, 400) as $discount) {
            $charged = Points::pointsCharged($discount, $pointsPerUnitOff, $exponent);

            expect(Points::worth($charged, $pointsPerUnitOff, $exponent))->toBeGreaterThanOrEqual($discount)
                ->and(Points::worth($charged - 1, $pointsPerUnitOff, $exponent))->toBeLessThan($discount);
        }
    })->with([1, 3, 7, 100, 150, 1_000, 100_000])->with([0, 2, 3]);

    it('reads the currency\'s own exponent for what points are worth', function () {
        // 100 points at 100 a unit are 1 unit: 1 of a whole-unit currency, 1,000 of a 3-decimal one.
        expect(Points::worth(100, 100, 0))->toBe(1)
            ->and(Points::worth(100, 100, 3))->toBe(1_000)
            ->and(Points::pointsCharged(1_000, 100, 3))->toBe(100)
            // The widest exponent Platform's currencies allow.
            ->and(Points::worth(1, 1, 6))->toBe(1_000_000);
    });
});

describe('the cap', function () {
    it('lets points pay at most the percent of the net subtotal, rounded down', function () {
        expect(Points::cap(10_000, 50))->toBe(5_000)
            ->and(Points::cap(999, 50))->toBe(499)
            ->and(Points::cap(10_000, 0))->toBe(0)
            ->and(Points::cap(10_000, 100))->toBe(10_000);
    });

    it('finds the largest count of points whose worth stays within the cap', function (int $pointsPerUnitOff, int $exponent) {
        foreach ([0, 1, 2, 33, 499, 5_000] as $cap) {
            $most = Points::mostPointsUnderCap($cap, $pointsPerUnitOff, $exponent);

            expect(Points::worth($most, $pointsPerUnitOff, $exponent))->toBeLessThanOrEqual($cap)
                ->and(Points::worth($most + 1, $pointsPerUnitOff, $exponent))->toBeGreaterThan($cap);
        }
    })->with([1, 3, 7, 100, 150, 1_000])->with([0, 2, 3]);
});

it('never overflows on the way: a product past 64 bits still comes out right', function () {
    // 9e16 halalas at 1,000 points a unit: the product is 9e19, past PHP_INT_MAX (≈9.2e18).
    expect(Points::earned(90_000_000_000_000_000, 1_000, 2))->toBe(900_000_000_000_000_000);
});

it('refuses a result past 64 bits as its own error, never the library\'s', function () {
    expect(fn () => Points::earned(PHP_INT_MAX, 2, 0))->toThrow(InvalidPointsAttribute::class, 'Invalid amount: too large to count')
        ->and(fn () => Points::worth(PHP_INT_MAX, 1, 1))->toThrow(InvalidPointsAttribute::class, 'Invalid amount: too large to count')
        ->and(fn () => Points::pointsCharged(PHP_INT_MAX, 2, 0))->toThrow(InvalidPointsAttribute::class, 'Invalid amount: too large to count')
        ->and(fn () => Points::mostPointsUnderCap(PHP_INT_MAX, 2, 0))->toThrow(InvalidPointsAttribute::class, 'Invalid amount: too large to count')
        // The largest result that fits still comes out.
        ->and(Points::earned(PHP_INT_MAX, 1, 0))->toBe(PHP_INT_MAX);
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
    'a negative amount earning' => [fn () => Points::earned(-1, 1, 2), 'amount'],
    'an earning rate of 0' => [fn () => Points::earned(100, 0, 2), 'rate'],
    'negative points' => [fn () => Points::worth(-1, 100, 2), 'points'],
    'a worth at a rate of 0' => [fn () => Points::worth(1, 0, 2), 'rate'],
    'a negative discount' => [fn () => Points::pointsCharged(-1, 100, 2), 'amount'],
    'a discount at a rate of 0' => [fn () => Points::pointsCharged(100, 0, 2), 'rate'],
    'a negative net subtotal' => [fn () => Points::cap(-1, 50), 'amount'],
    'a percent past 100' => [fn () => Points::cap(100, 101), 'percent'],
    'a percent below 0' => [fn () => Points::cap(100, -1), 'percent'],
    'a negative cap' => [fn () => Points::mostPointsUnderCap(-1, 100, 2), 'amount'],
    'a cap at a rate of 0' => [fn () => Points::mostPointsUnderCap(100, 0, 2), 'rate'],
    'an exponent past 6' => [fn () => Points::worth(1, 100, 7), 'currency'],
    'an exponent below 0' => [fn () => Points::worth(1, 100, -1), 'currency'],
]);
