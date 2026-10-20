<?php

declare(strict_types=1);

use Modules\Sales\Domain\Service\ReturnRefunds;

/*
| What a completed return refunds (sales.md §1.8): each piece its goods share plus its VAT share, by
| running totals so a line returned in parts lands exactly on its shares; a gift nothing; the shipping
| and its VAT share with the return that brings every bought piece back, once.
*/

/** @return array<string, array{quantity: int, total: int, coupon: int, points: int, vat: int, gift: bool}> */
function refundLines(): array
{
    return [
        // 3 pieces: total 100.00, coupon 10.00, points 5.01, VAT 12.74.
        'a' => ['quantity' => 3, 'total' => 100_00, 'coupon' => 10_00, 'points' => 5_01, 'vat' => 12_74, 'gift' => false],
        'b' => ['quantity' => 1, 'total' => 50_00, 'coupon' => 0, 'points' => 2_50, 'vat' => 7_12, 'gift' => false],
        'g' => ['quantity' => 1, 'total' => 0, 'coupon' => 0, 'points' => 0, 'vat' => 0, 'gift' => true],
    ];
}

it('moves each share by running totals, so a line returned piece by piece lands exactly on its shares', function () {
    $lines = refundLines();
    $first = ReturnRefunds::of($lines, [], ['a' => 1], false, 25_00, 3_75);
    $second = ReturnRefunds::of($lines, ['a' => 1], ['a' => 1], false, 25_00, 3_75);
    $third = ReturnRefunds::of($lines, ['a' => 2], ['a' => 1], false, 25_00, 3_75);

    // floor(S/3), floor(2S/3) - floor(S/3), S - floor(2S/3), for each share — the goods (84.99) as one.
    expect([$first['goods'], $second['goods'], $third['goods']])->toBe([28_33, 28_33, 28_33])
        ->and([$first['points'], $second['points'], $third['points']])->toBe([1_67, 1_67, 1_67])
        ->and($first['refund'] + $second['refund'] + $third['refund'])->toBe(100_00 - 10_00 - 5_01 + 12_74)
        ->and([$first['shipping'], $second['shipping'], $third['shipping']])->toBe([false, false, false]);
});

it('refunds nothing for the gift, and the shipping with the return that brings every bought piece back', function () {
    $lines = refundLines();

    $gift = ReturnRefunds::of($lines, [], ['g' => 1], false, 25_00, 3_75);
    $all = ReturnRefunds::of($lines, ['g' => 1], ['a' => 3, 'b' => 1], false, 25_00, 3_75);

    expect($gift)->toBe(['refund' => 0, 'goods' => 0, 'points' => 0, 'shipping' => false])
        ->and($all['shipping'])->toBeTrue()
        ->and($all['refund'])->toBe(150_00 - 10_00 - 7_51 + 19_86 + 25_00 + 3_75)
        ->and($all['goods'])->toBe(150_00 - 10_00 - 7_51)
        ->and($all['points'])->toBe(7_51);
});

it('carries the shipping once only, and never while a bought piece is still out', function () {
    $lines = refundLines();

    // The gift kept: every bought piece back is enough.
    expect(ReturnRefunds::of($lines, [], ['a' => 3, 'b' => 1], false, 25_00, 3_75)['shipping'])->toBeTrue()
        ->and(ReturnRefunds::of($lines, ['a' => 3], ['b' => 1], true, 25_00, 3_75)['shipping'])->toBeFalse()
        ->and(ReturnRefunds::of($lines, ['a' => 2], ['b' => 1], false, 25_00, 3_75)['shipping'])->toBeFalse()
        ->and(ReturnRefunds::of($lines, ['a' => 3], ['b' => 1], false, 25_00, 3_75)['refund'])->toBe(50_00 - 2_50 + 7_12 + 25_00 + 3_75);
});

it('never moves goods below 0, however a line nearly paid by its coupon and points rounds', function () {
    // Goods of 0.00 over 2 pieces: rounding total, coupon and points each on its own gave +0.01 then -0.01.
    $lines = ['a' => ['quantity' => 2, 'total' => 3_00, 'coupon' => 1_51, 'points' => 1_49, 'vat' => 0, 'gift' => false]];

    $first = ReturnRefunds::of($lines, [], ['a' => 1], false, 0, 0);
    $second = ReturnRefunds::of($lines, ['a' => 1], ['a' => 1], false, 0, 0);

    expect([$first['goods'], $second['goods'], $first['refund'], $second['refund']])->toBe([0, 0, 0, 0])
        ->and($first['points'] + $second['points'])->toBe(1_49);
});

it('works in whole numbers beyond PHP integers: a large line of many pieces', function () {
    // 10^13 × 10^6 = 10^19, past PHP_INT_MAX (about 9.2 × 10^18).
    $lines = ['a' => ['quantity' => 1_000_000, 'total' => 10_000_000_000_000, 'coupon' => 0, 'points' => 0, 'vat' => 0, 'gift' => false]];

    expect(ReturnRefunds::of($lines, ['a' => 999_999], ['a' => 1], true, 0, 0)['refund'])->toBe(10_000_000);
});

it('refuses more pieces back than a line holds, a line the order lacks, and fewer than none', function (array $before, array $now) {
    expect(fn () => ReturnRefunds::of(refundLines(), $before, $now, false, 0, 0))->toThrow(InvalidArgumentException::class);
})->with([
    'too many now' => [[], ['a' => 4]],
    'too many with earlier' => [['a' => 2], ['a' => 2]],
    'no such line' => [[], ['z' => 1]],
    'below none' => [[], ['a' => -1]],
]);
