<?php

declare(strict_types=1);

use Modules\Sales\Domain\Service\OrderShares;
use Shared\Domain\ValueObject\Money;

/*
| The shares (sales.md §1.8): the coupon's as Promotions gave them, the points' over what the coupon left
| of each line, the VAT's over each line's taxable amount and the shipping — each adding up exactly, no
| line's goods share below 0; per piece by running totals, so a line returned in parts lands on its share.
*/

function sar(int $minor): Money
{
    return Money::of($minor, 'SAR');
}

/**
 * @param  list<int>  $minors
 * @return list<Money>
 */
function sars(array $minors): array
{
    return array_map(static fn (int $minor): Money => sar($minor), $minors);
}

/**
 * @param  array{lines: list<array{coupon: Money, points: Money, vat: Money}>, shippingVat: Money}  $shares
 * @return array{list<array{int, int, int}>, int}
 */
function shareMinors(array $shares): array
{
    return [array_map(static fn (array $line): array => [$line['coupon']->minorUnits, $line['points']->minorUnits, $line['vat']->minorUnits], $shares['lines']), $shares['shippingVat']->minorUnits];
}

it('keeps the coupon shares as given, spreads the points over what the coupon left, and the VAT over the taxable amounts and the shipping', function () {
    // Lines 200.00 and 50.00; the coupon took 30.00 and 0; points 22.00; shipping 25.00.
    // Points by 170.00 : 50.00 -> 17.00 and 5.00. Taxable 153.00, 45.00, shipping 25.00 = 223.00; VAT 33.45.
    $shares = OrderShares::of(sars([200_00, 50_00]), sars([30_00, 0]), sar(22_00), sar(33_45), sar(25_00));

    expect(shareMinors($shares))->toBe([[[30_00, 17_00, 22_95], [0, 5_00, 6_75]], 3_75]);
});

it('adds every kind up to its amount exactly, the odd units going to the largest remainders', function () {
    $shares = OrderShares::of(sars([1_00, 1_00, 1_00]), sars([0, 0, 0]), sar(1), sar(2), sar(0));
    [$lines, $shipping] = shareMinors($shares);

    expect(array_sum(array_column($lines, 1)))->toBe(1)
        ->and(array_sum(array_column($lines, 2)) + $shipping)->toBe(2)
        ->and($lines)->toBe([[0, 1, 0], [0, 0, 1], [0, 0, 1]])
        ->and($shipping)->toBe(0);
});

it('gives no points share to a line the coupon took whole, and none to the gift', function () {
    $shares = OrderShares::of(sars([40_00, 60_00, 0]), sars([40_00, 0, 0]), sar(6_00), sar(0), sar(0));

    expect(shareMinors($shares))->toBe([[[40_00, 0, 0], [0, 6_00, 0], [0, 0, 0]], 0]);
});

it('gives none of a kind whose amount is 0', function () {
    $shares = OrderShares::of(sars([0]), sars([0]), sar(0), sar(0), sar(0));

    expect(shareMinors($shares))->toBe([[[0, 0, 0]], 0]);
});

it('puts all the VAT on the shipping when the goods are free', function () {
    $shares = OrderShares::of(sars([10_00]), sars([4_00]), sar(6_00), sar(3_75), sar(25_00));

    expect(shareMinors($shares))->toBe([[[4_00, 6_00, 0]], 3_75]);
});

it('refuses lists that do not line up, a coupon share above its line or below 0, and an amount with nowhere to go', function (Closure $call, string $class) {
    expect($call)->toThrow($class);
})->with([
    'no lines' => [fn () => OrderShares::of([], [], sar(0), sar(0), sar(0)), InvalidArgumentException::class],
    'one share short' => [fn () => OrderShares::of(sars([1_00, 1_00]), sars([0]), sar(0), sar(0), sar(0)), InvalidArgumentException::class],
    'a share above its line' => [fn () => OrderShares::of(sars([1_00]), sars([1_01]), sar(0), sar(0), sar(0)), InvalidArgumentException::class],
    'a share below 0' => [fn () => OrderShares::of(sars([1_00]), sars([-1]), sar(0), sar(0), sar(0)), InvalidArgumentException::class],
    'points with nothing left' => [fn () => OrderShares::of(sars([1_00]), sars([1_00]), sar(1), sar(0), sar(0)), InvalidArgumentException::class],
    'points above what the coupon left' => [fn () => OrderShares::of(sars([1_00, 2_00]), sars([50, 0]), sar(2_51), sar(0), sar(0)), InvalidArgumentException::class],
    'negative points' => [fn () => OrderShares::of(sars([1_00]), sars([0]), sar(-1), sar(0), sar(0)), InvalidArgumentException::class],
    'negative VAT' => [fn () => OrderShares::of(sars([1_00]), sars([0]), sar(0), sar(-1), sar(0)), InvalidArgumentException::class],
    'negative shipping' => [fn () => OrderShares::of(sars([1_00]), sars([0]), sar(0), sar(0), sar(-1)), InvalidArgumentException::class],
    'VAT with nothing taxable' => [fn () => OrderShares::of(sars([0]), sars([0]), sar(0), sar(1), sar(0)), LogicException::class],
]);

it('gives each piece its part of a share by running totals, the last taking what is left', function () {
    // A share of 10.00 over 3 pieces: 3.33, then 3.33, then 3.34.
    $share = sar(10_00);
    $one = OrderShares::forPieces($share, 3, 0, 1);
    $two = OrderShares::forPieces($share, 3, 1, 1);
    $three = OrderShares::forPieces($share, 3, 2, 1);

    expect([$one->minorUnits, $two->minorUnits, $three->minorUnits])->toBe([3_33, 3_33, 3_34])
        ->and(OrderShares::forPieces($share, 3, 0, 3)->minorUnits)->toBe(10_00)
        ->and(OrderShares::forPieces($share, 3, 1, 2)->minorUnits)->toBe(6_67)
        ->and(OrderShares::forPieces($share, 3, 1, 0)->minorUnits)->toBe(0);
});

it('refuses pieces out of range', function (int $pieces, int $before, int $count) {
    expect(fn () => OrderShares::forPieces(sar(1_00), $pieces, $before, $count))->toThrow(InvalidArgumentException::class);
})->with([
    'no pieces' => [0, 0, 0],
    'more than the line' => [3, 2, 2],
    'before below 0' => [3, -1, 1],
    'count below 0' => [3, 1, -1],
]);

it('refuses a share below 0 for pieces', function () {
    expect(fn () => OrderShares::forPieces(sar(-1_00), 3, 0, 1))->toThrow(InvalidArgumentException::class);
});

it('takes points that use exactly what the coupon left', function () {
    $shares = OrderShares::of(sars([1_00, 2_00]), sars([50, 0]), sar(2_50), sar(0), sar(0));

    expect(shareMinors($shares))->toBe([[[50, 50, 0], [0, 2_00, 0]], 0]);
});

it('works past the ints a multiplication of a large share would overflow', function () {
    $share = sar(9_000_000_000_000_000);

    expect(OrderShares::forPieces($share, 1_000_000, 999_999, 1)->minorUnits)->toBe(9_000_000_000);
});
