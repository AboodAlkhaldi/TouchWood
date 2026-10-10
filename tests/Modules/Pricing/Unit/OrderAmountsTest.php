<?php

declare(strict_types=1);

use Modules\Pricing\Domain\Exception\AmountsInvalid;
use Modules\Pricing\Domain\Exception\LinesWithoutPrice;
use Modules\Pricing\Domain\Exception\PercentOutOfRange;
use Modules\Pricing\Domain\Service\FixedOff;
use Modules\Pricing\Domain\Service\OrderTotals;
use Modules\Pricing\Domain\Service\PercentOff;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Shared\Domain\ValueObject\Money;

/*
| The amounts (pricing.md §1.1 rule 5, §1.5, §1.7; §8 #2 and #3): a percentage off rounded half up to
| the coin, a fixed amount that skips a size it would take to 0, and every total of an order with VAT
| rounded once, half up. Pure: no database, no clock.
*/

/**
 * @param  list<string>  $unpriced
 */
function pricingAmountsPrices(int $gross, int $net, int $rateBasisPoints = 1500, string $currency = 'SAR', array $unpriced = []): CartPricesDto
{
    return new CartPricesDto('01J9STORE0000000000000000A', $currency, $rateBasisPoints, [], $unpriced, Money::of($gross, $currency), Money::of($net, $currency), new DateTimeImmutable('2026-10-10 12:00:00'), null);
}

describe('a percentage off (§1.1 rule 5: half up to the smallest coin)', function () {
    it('works out the price, rounded once', function (int $price, string $currency, int $basisPoints, int $expected) {
        expect(PercentOff::price(Money::of($price, $currency), $basisPoints)->minorUnits)->toBe($expected);
    })->with([
        '15 % of 100.00 SAR' => [10000, 'SAR', 1500, 8500],
        'half a halala rounds up: 50 % of 1.01' => [101, 'SAR', 5000, 51],
        'under half rounds down: 33.33 % of 1.00' => [100, 'SAR', 3333, 67],
        'three decimals, half a fils rounds up: 15 % of 1.001 KWD' => [1001, 'KWD', 1500, 851],
        'three decimals: 12.5 % of 10.000 KWD' => [10000, 'KWD', 1250, 8750],
        'the smallest step: 0.01 % of 1.00' => [100, 'SAR', 1, 100],
        'the largest step: 99.99 % of 100.00' => [10000, 'SAR', 9999, 1],
    ]);

    it('refuses a percentage not above 0 and below 100', function (int $basisPoints) {
        expect(fn () => PercentOff::price(Money::of(10000, 'SAR'), $basisPoints))->toThrow(PercentOutOfRange::class);
    })->with(['nothing off' => [0], 'everything off' => [10000], 'more than everything' => [12000], 'a negative percentage' => [-500]]);
});

describe('a fixed amount off (§1.5)', function () {
    it('takes it off the price', function () {
        expect(FixedOff::price(Money::of(10000, 'SAR'), Money::of(2500, 'SAR'))?->minorUnits)->toBe(7500);
    });

    it('skips a size it would take to 0 or below', function (int $off) {
        expect(FixedOff::price(Money::of(10000, 'SAR'), Money::of($off, 'SAR')))->toBeNull();
    })->with(['to exactly 0' => [10000], 'below 0' => [15000]]);
});

describe('the totals (§1.7, handoff §10.2)', function () {
    it('works out every amount', function () {
        $totals = OrderTotals::of(pricingAmountsPrices(gross: 120000, net: 100000), Money::of(10000, 'SAR'), Money::of(5000, 'SAR'), Money::of(2500, 'SAR'));

        expect([
            $totals->grossSubtotal->minorUnits, $totals->netSubtotal->minorUnits, $totals->couponDiscount->minorUnits,
            $totals->pointsDiscount->minorUnits, $totals->goodsTotal->minorUnits, $totals->shipping->minorUnits,
            $totals->taxableBase->minorUnits, $totals->vat->minorUnits, $totals->orderTotal->minorUnits, $totals->taxRateBasisPoints,
        ])->toBe([120000, 100000, 10000, 5000, 85000, 2500, 87500, 13125, 100625, 1500]);
    });

    it('rounds VAT once, half up: exactly half a halala goes up, less goes down', function (int $net, int $vat) {
        expect(OrderTotals::of(pricingAmountsPrices(gross: $net, net: $net), Money::zero('SAR'), Money::zero('SAR'), Money::zero('SAR'))->vat->minorUnits)->toBe($vat);
    })->with([
        '1010 × 15 % = 151.5' => [1010, 152],
        '1001 × 15 % = 150.15' => [1001, 150],
        '1003 × 15 % = 150.45' => [1003, 150],
    ]);

    it('uses the rate the prices carry, with three decimals too', function () {
        $totals = OrderTotals::of(pricingAmountsPrices(gross: 10010, net: 10010, rateBasisPoints: 500, currency: 'KWD'), Money::zero('KWD'), Money::zero('KWD'), Money::zero('KWD'));

        // 10.010 KWD × 5 % = 0.5005 → 0.501 (half a fils up).
        expect($totals->vat->minorUnits)->toBe(501)
            ->and($totals->orderTotal->minorUnits)->toBe(10511);
    });

    it('lets the discounts take the goods to exactly 0', function () {
        $totals = OrderTotals::of(pricingAmountsPrices(gross: 10000, net: 10000), Money::of(6000, 'SAR'), Money::of(4000, 'SAR'), Money::of(2000, 'SAR'));

        expect($totals->goodsTotal->minorUnits)->toBe(0)
            ->and($totals->taxableBase->minorUnits)->toBe(2000)
            ->and($totals->vat->minorUnits)->toBe(300);
    });

    it('refuses prices holding a line with no price', function () {
        expect(fn () => OrderTotals::of(pricingAmountsPrices(gross: 10000, net: 10000, unpriced: ['01J9VARIANT00000000000000A']), Money::zero('SAR'), Money::zero('SAR'), Money::zero('SAR')))
            ->toThrow(LinesWithoutPrice::class);
    });

    it('refuses an amount in another currency, a negative amount, and discounts above the net subtotal', function (Money $coupon, Money $points, Money $shipping) {
        expect(fn () => OrderTotals::of(pricingAmountsPrices(gross: 10000, net: 10000), $coupon, $points, $shipping))->toThrow(AmountsInvalid::class);
    })->with([
        'a coupon in another currency' => [Money::of(100, 'KWD'), Money::zero('SAR'), Money::zero('SAR')],
        'shipping in another currency' => [Money::zero('SAR'), Money::zero('SAR'), Money::of(100, 'AED')],
        'a negative coupon' => [Money::of(-100, 'SAR'), Money::zero('SAR'), Money::zero('SAR')],
        'negative points' => [Money::zero('SAR'), Money::of(-1, 'SAR'), Money::zero('SAR')],
        'negative shipping' => [Money::zero('SAR'), Money::zero('SAR'), Money::of(-1, 'SAR')],
        'discounts one halala above the net' => [Money::of(6000, 'SAR'), Money::of(4001, 'SAR'), Money::zero('SAR')],
    ]);

    it('gives the same answer to the same input', function () {
        $prices = pricingAmountsPrices(gross: 120000, net: 100000);

        expect(OrderTotals::of($prices, Money::of(10000, 'SAR'), Money::of(5000, 'SAR'), Money::of(2500, 'SAR')))
            ->toEqual(OrderTotals::of($prices, Money::of(10000, 'SAR'), Money::of(5000, 'SAR'), Money::of(2500, 'SAR')));
    });
});
