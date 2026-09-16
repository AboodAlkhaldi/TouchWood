<?php

declare(strict_types=1);

use Brick\Math\RoundingMode;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\MoneyException;

/**
 * @param  list<Money>  $shares
 * @return list<int>
 */
function minorUnitsOf(array $shares): array
{
    return array_map(fn (Money $share): int => $share->minorUnits, $shares);
}

describe('creating', function () {
    it('holds minor units and a currency code', function () {
        $money = Money::of(1250, 'SAR');

        expect($money->minorUnits)->toBe(1250)
            ->and($money->currencyCode)->toBe('SAR');
    });

    it('creates zero', function () {
        expect(Money::zero('EGP')->isZero())->toBeTrue()
            ->and(Money::zero('EGP')->currencyCode)->toBe('EGP');
    });

    it('rejects a malformed currency code', function (string $code) {
        Money::of(100, $code);
    })->throws(MoneyException::class)->with(['sar', 'SA', 'SARS', '', 'S1R', "SAR\n"]);
});

describe('parsing decimals', function () {
    it('reads the exponent it is given instead of assuming two decimals', function (string $amount, int $exponent, int $expected) {
        expect(Money::fromDecimal($amount, 'XTS', $exponent)->minorUnits)->toBe($expected);
    })->with([
        'two decimals' => ['1234.50', 2, 123450],
        'fewer decimals than allowed' => ['12.5', 2, 1250],
        'whole number' => ['12', 2, 1200],
        'negative' => ['-0.01', 2, -1],
        'no decimals currency' => ['12', 0, 12],
        'three decimals currency' => ['1.234', 3, 1234],
    ]);

    it('rejects more decimals than the currency allows instead of rounding', function () {
        Money::fromDecimal('12.345', 'SAR', 2);
    })->throws(MoneyException::class, 'more than 2 decimal places');

    it('rejects anything that is not a plain decimal', function (string $amount) {
        Money::fromDecimal($amount, 'SAR', 2);
    })->throws(MoneyException::class)->with(['abc', '1,000.00', '', ' 12.50', '1e3', '.50', '+12', "12\n"]);

    it('rejects a negative exponent', function () {
        Money::fromDecimal('1', 'SAR', -1);
    })->throws(MoneyException::class);

    it('rejects an amount that does not fit in 64 bits', function () {
        Money::fromDecimal('92233720368547758.08', 'SAR', 2);
    })->throws(MoneyException::class, '64-bit');
});

describe('arithmetic', function () {
    it('adds and subtracts', function () {
        $a = Money::of(1050, 'SAR');
        $b = Money::of(250, 'SAR');

        expect($a->add($b)->minorUnits)->toBe(1300)
            ->and($a->subtract($b)->minorUnits)->toBe(800)
            ->and($b->subtract($a)->minorUnits)->toBe(-800);
    });

    it('never mixes currencies', function (string $operation) {
        Money::of(100, 'SAR')->{$operation}(Money::of(100, 'AED'));
    })->throws(MoneyException::class, 'Cannot combine SAR with AED')->with(['add', 'subtract', 'compareTo', 'isAtLeast']);

    it('fails loudly on overflow instead of turning into a float', function () {
        Money::of(PHP_INT_MAX, 'SAR')->add(Money::of(1, 'SAR'));
    })->throws(MoneyException::class, '64-bit');

    it('is immutable', function () {
        $original = Money::of(100, 'SAR');
        $original->add(Money::of(50, 'SAR'));

        expect($original->minorUnits)->toBe(100);
    });
});

describe('multiplying', function () {
    it('multiplies by an integer', function () {
        expect(Money::of(1999, 'SAR')->multiply(3, RoundingMode::Unnecessary)->minorUnits)->toBe(5997);
    });

    it('multiplies by an exact decimal with the requested rounding', function (int $amount, string $factor, RoundingMode $rounding, int $expected) {
        expect(Money::of($amount, 'SAR')->multiply($factor, $rounding)->minorUnits)->toBe($expected);
    })->with([
        'VAT half up' => [1005, '0.15', RoundingMode::HalfUp, 151],
        'VAT rounded down' => [1005, '0.15', RoundingMode::Down, 150],
        'negative half up' => [-1005, '0.15', RoundingMode::HalfUp, -151],
        'tie half up' => [10, '0.25', RoundingMode::HalfUp, 3],
        'tie half down' => [10, '0.25', RoundingMode::HalfDown, 2],
        'tie half even' => [10, '0.25', RoundingMode::HalfEven, 2],
        'exact with no rounding' => [100, '0.15', RoundingMode::Unnecessary, 15],
    ]);

    it('refuses to round when rounding was not allowed', function () {
        Money::of(101, 'SAR')->multiply('0.15', RoundingMode::Unnecessary);
    })->throws(MoneyException::class, 'needs rounding');

    it('rejects a factor that is not a plain decimal', function (string $factor) {
        Money::of(100, 'SAR')->multiply($factor, RoundingMode::HalfUp);
    })->throws(MoneyException::class)->with(['abc', '1e2', ' 0.15', '0,15', "0.15\n"]);

    it('fails loudly on overflow', function () {
        Money::of(PHP_INT_MAX, 'SAR')->multiply(2, RoundingMode::Unnecessary);
    })->throws(MoneyException::class, '64-bit');
});

describe('allocating', function () {
    it('splits a 100.00 discount across three lines without losing a halala', function () {
        $shares = Money::fromDecimal('100.00', 'SAR', 2)->allocate(1, 1, 1);

        expect(minorUnitsOf($shares))->toBe([3334, 3333, 3333]);
    });

    it('splits in proportion to line amounts', function () {
        $shares = Money::of(10000, 'SAR')->allocate(12999, 4550, 30000);

        expect(minorUnitsOf($shares))->toBe([2734, 957, 6309])
            ->and(array_sum(minorUnitsOf($shares)))->toBe(10000);
    });

    it('gives left-over units to the largest remainders, not simply the first share', function () {
        // Exact shares are 1.25 and 3.75: the 0.75 remainder earns the extra unit.
        expect(minorUnitsOf(Money::of(5, 'SAR')->allocate(1, 3)))->toBe([1, 4]);
    });

    it('breaks remainder ties in favour of earlier shares', function () {
        expect(minorUnitsOf(Money::of(2, 'SAR')->allocate(1, 1, 1)))->toBe([1, 1, 0]);
    });

    it('allocates negative amounts symmetrically', function () {
        expect(minorUnitsOf(Money::of(-10000, 'SAR')->allocate(1, 1, 1)))->toBe([-3334, -3333, -3333]);
    });

    it('gives nothing to a zero ratio', function () {
        expect(minorUnitsOf(Money::of(1001, 'SAR')->allocate(1, 0, 1)))->toBe([501, 0, 500]);
    });

    it('allocates zero as zeros', function () {
        expect(minorUnitsOf(Money::zero('SAR')->allocate(3, 7)))->toBe([0, 0]);
    });

    it('keeps the currency on every share', function () {
        foreach (Money::of(100, 'AED')->allocate(1, 2) as $share) {
            expect($share->currencyCode)->toBe('AED');
        }
    });

    it('handles the extremes of a 64-bit amount', function () {
        expect(minorUnitsOf(Money::of(PHP_INT_MIN, 'SAR')->allocate(1)))->toBe([PHP_INT_MIN])
            ->and(array_sum(minorUnitsOf(Money::of(PHP_INT_MAX, 'SAR')->allocate(PHP_INT_MAX, PHP_INT_MAX, 1))))->toBe(PHP_INT_MAX);
    });

    it('rejects ratios that cannot be allocated', function (array $ratios) {
        Money::of(100, 'SAR')->allocate(...$ratios);
    })->throws(MoneyException::class)->with([
        'no ratios' => [[]],
        'negative ratio' => [[1, -1]],
        'all zero' => [[0, 0]],
    ]);

    it('always sums to the original and never strays a whole unit from the exact share', function () {
        mt_srand(20260916);

        for ($case = 0; $case < 500; $case++) {
            $amount = mt_rand(-1_000_000_000, 1_000_000_000);
            $ratios = array_map(fn () => mt_rand(0, 1_000_000), range(1, mt_rand(1, 6)));
            $ratios[0] += 1;
            $total = array_sum($ratios);

            $shares = minorUnitsOf(Money::of($amount, 'SAR')->allocate(...$ratios));

            expect(array_sum($shares))->toBe($amount);

            foreach ($shares as $i => $share) {
                // |share - amount * ratio / total| < 1, kept in integers.
                expect(abs($share * $total - $amount * $ratios[$i]))->toBeLessThan($total);
            }
        }
    });
});

describe('comparing', function () {
    it('compares amounts of the same currency', function () {
        $small = Money::of(500, 'SAR');
        $large = Money::of(1000, 'SAR');

        expect($small->compareTo($large))->toBe(-1)
            ->and($large->compareTo($small))->toBe(1)
            ->and($small->compareTo(Money::of(500, 'SAR')))->toBe(0)
            ->and($large->isAtLeast($small))->toBeTrue()
            ->and($small->isAtLeast(Money::of(500, 'SAR')))->toBeTrue()
            ->and($small->isAtLeast($large))->toBeFalse();
    });

    it('treats different currencies as unequal', function () {
        expect(Money::of(100, 'SAR')->equals(Money::of(100, 'SAR')))->toBeTrue()
            ->and(Money::of(100, 'SAR')->equals(Money::of(100, 'AED')))->toBeFalse()
            ->and(Money::of(100, 'SAR')->equals(Money::of(101, 'SAR')))->toBeFalse();
    });

    it('reports its sign', function () {
        expect(Money::of(1, 'SAR')->isPositive())->toBeTrue()
            ->and(Money::of(-1, 'SAR')->isNegative())->toBeTrue()
            ->and(Money::of(0, 'SAR')->isZero())->toBeTrue()
            ->and(Money::of(0, 'SAR')->isPositive())->toBeFalse()
            ->and(Money::of(0, 'SAR')->isNegative())->toBeFalse();
    });
});

describe('formatting', function () {
    it('formats with Latin digits, thousands separators and the currency exponent', function (int $minorUnits, int $exponent, string $expected) {
        expect(Money::of($minorUnits, 'XTS')->format($exponent))->toBe($expected);
    })->with([
        'two decimals' => [123450, 2, '1,234.50'],
        'below one' => [5, 2, '0.05'],
        'zero' => [0, 2, '0.00'],
        'negative' => [-123450, 2, '-1,234.50'],
        'no decimals' => [1234567, 0, '1,234,567'],
        'three decimals' => [1234, 3, '1.234'],
        'no grouping needed' => [99999, 2, '999.99'],
        'most negative 64-bit value' => [PHP_INT_MIN, 2, '-92,233,720,368,547,758.08'],
    ]);

    it('writes a plain decimal for inputs and exchange', function () {
        expect(Money::of(123450, 'SAR')->toDecimal(2))->toBe('1234.50')
            ->and(Money::of(-7, 'SAR')->toDecimal(3))->toBe('-0.007')
            ->and(Money::of(42, 'SAR')->toDecimal(0))->toBe('42');
    });

    it('round-trips through its decimal form', function (int $minorUnits, int $exponent) {
        $money = Money::of($minorUnits, 'SAR');

        expect(Money::fromDecimal($money->toDecimal($exponent), 'SAR', $exponent)->equals($money))->toBeTrue();
    })->with([[123450, 2], [-1, 2], [0, 2], [987654321, 3], [42, 0]]);

    it('rejects a negative exponent', function () {
        Money::of(100, 'SAR')->format(-2);
    })->throws(MoneyException::class);
});
