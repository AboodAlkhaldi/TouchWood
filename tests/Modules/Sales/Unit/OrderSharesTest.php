<?php

declare(strict_types=1);

use Modules\Sales\Domain\Service\OrderShares;
use Shared\Domain\ValueObject\Money;

/*
| The shares (sales.md §1.8): the coupon's as Promotions gave them, the points' over what the coupon left
| of each line, the VAT's over each line's taxable amount and the shipping — each adding up exactly, no
| line's goods share below 0; per piece by running totals, so a line returned in parts lands on its share.
*/

function orderSar(int $minor): Money
{
    return Money::of($minor, 'SAR');
}

/**
 * @param  list<int>  $minors
 * @return list<Money>
 */
function orderSars(array $minors): array
{
    return array_map(static fn (int $minor): Money => orderSar($minor), $minors);
}

/**
 * @param  array{lines: list<array{coupon: Money, points: Money, vat: Money}>, shippingVat: Money}  $shares
 * @return array{list<array{int, int, int}>, int}
 */
function orderShareMinors(array $shares): array
{
    return [array_map(static fn (array $line): array => [$line['coupon']->minorUnits, $line['points']->minorUnits, $line['vat']->minorUnits], $shares['lines']), $shares['shippingVat']->minorUnits];
}

it('keeps the coupon shares as given, spreads the points over what the coupon left, and the VAT over the taxable amounts and the shipping', function () {
    // Lines 200.00 and 50.00; the coupon took 30.00 and 0; points 22.00; shipping 25.00.
    // Points by 170.00 : 50.00 -> 17.00 and 5.00. Taxable 153.00, 45.00, shipping 25.00 = 223.00; VAT 33.45.
    $shares = OrderShares::of(orderSars([200_00, 50_00]), orderSars([30_00, 0]), orderSar(22_00), orderSar(33_45), orderSar(25_00));

    expect(orderShareMinors($shares))->toBe([[[30_00, 17_00, 22_95], [0, 5_00, 6_75]], 3_75]);
});

it('adds every kind up to its amount exactly, the odd units going to the largest remainders', function () {
    $shares = OrderShares::of(orderSars([1_00, 1_00, 1_00]), orderSars([0, 0, 0]), orderSar(1), orderSar(2), orderSar(0));
    [$lines, $shipping] = orderShareMinors($shares);

    expect(array_sum(array_column($lines, 1)))->toBe(1)
        ->and(array_sum(array_column($lines, 2)) + $shipping)->toBe(2)
        ->and($lines)->toBe([[0, 1, 0], [0, 0, 1], [0, 0, 1]])
        ->and($shipping)->toBe(0);
});

it('gives no points share to a line the coupon took whole, and none to the gift', function () {
    $shares = OrderShares::of(orderSars([40_00, 60_00, 0]), orderSars([40_00, 0, 0]), orderSar(6_00), orderSar(0), orderSar(0));

    expect(orderShareMinors($shares))->toBe([[[40_00, 0, 0], [0, 6_00, 0], [0, 0, 0]], 0]);
});

it('gives none of a kind whose amount is 0', function () {
    $shares = OrderShares::of(orderSars([0]), orderSars([0]), orderSar(0), orderSar(0), orderSar(0));

    expect(orderShareMinors($shares))->toBe([[[0, 0, 0]], 0]);
});

it('puts all the VAT on the shipping when the goods are free', function () {
    $shares = OrderShares::of(orderSars([10_00]), orderSars([4_00]), orderSar(6_00), orderSar(3_75), orderSar(25_00));

    expect(orderShareMinors($shares))->toBe([[[4_00, 6_00, 0]], 3_75]);
});

it('refuses lists that do not line up, a coupon share above its line or below 0, and an amount with nowhere to go', function (Closure $call, string $class) {
    expect($call)->toThrow($class);
})->with([
    'no lines' => [fn () => OrderShares::of([], [], orderSar(0), orderSar(0), orderSar(0)), InvalidArgumentException::class],
    'one share short' => [fn () => OrderShares::of(orderSars([1_00, 1_00]), orderSars([0]), orderSar(0), orderSar(0), orderSar(0)), InvalidArgumentException::class],
    'a share above its line' => [fn () => OrderShares::of(orderSars([1_00]), orderSars([1_01]), orderSar(0), orderSar(0), orderSar(0)), InvalidArgumentException::class],
    'a share below 0' => [fn () => OrderShares::of(orderSars([1_00]), orderSars([-1]), orderSar(0), orderSar(0), orderSar(0)), InvalidArgumentException::class],
    'points with nothing left' => [fn () => OrderShares::of(orderSars([1_00]), orderSars([1_00]), orderSar(1), orderSar(0), orderSar(0)), InvalidArgumentException::class],
    'points above what the coupon left' => [fn () => OrderShares::of(orderSars([1_00, 2_00]), orderSars([50, 0]), orderSar(2_51), orderSar(0), orderSar(0)), InvalidArgumentException::class],
    'negative points' => [fn () => OrderShares::of(orderSars([1_00]), orderSars([0]), orderSar(-1), orderSar(0), orderSar(0)), InvalidArgumentException::class],
    'negative VAT' => [fn () => OrderShares::of(orderSars([1_00]), orderSars([0]), orderSar(0), orderSar(-1), orderSar(0)), InvalidArgumentException::class],
    'negative shipping' => [fn () => OrderShares::of(orderSars([1_00]), orderSars([0]), orderSar(0), orderSar(0), orderSar(-1)), InvalidArgumentException::class],
    'VAT with nothing taxable' => [fn () => OrderShares::of(orderSars([0]), orderSars([0]), orderSar(0), orderSar(1), orderSar(0)), LogicException::class],
]);

it('takes points that use exactly what the coupon left', function () {
    $shares = OrderShares::of(orderSars([1_00, 2_00]), orderSars([50, 0]), orderSar(2_50), orderSar(0), orderSar(0));

    expect(orderShareMinors($shares))->toBe([[[50, 50, 0], [0, 2_00, 0]], 0]);
});
