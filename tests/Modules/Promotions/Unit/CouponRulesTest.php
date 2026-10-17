<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Domain\Model\CouponLine;
use Modules\Promotions\Domain\Model\CouponRule;
use Modules\Promotions\Domain\Model\CouponUsage;
use Modules\Promotions\Domain\Model\RuleKind;
use Modules\Promotions\Domain\Model\RuleTarget;
use Modules\Promotions\Domain\Service\CouponRules;
use Modules\Promotions\Domain\Service\DiscountRoom;
use Modules\Promotions\Public\Enums\Audience;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Modules\Promotions\Public\Enums\DiscountKind;
use Shared\Domain\ValueObject\Money;

/*
| The coupon's rules, pure (promotions.md §1.2, §1.3, §1.6; the scenarios of §8): which lines a coupon
| covers, what it takes off and how that is spread, when it is refused whole, and the room under the
| ceiling. Amounts in halalas.
*/

function sar(int $minor): Money
{
    return Money::of($minor, 'SAR');
}

/**
 * @param  array<string, mixed>  $with
 */
function coupon(array $with = []): Coupon
{
    $values = [
        'eligibility' => CouponEligibility::Public, 'kind' => DiscountKind::Percent, 'percent' => 15, 'cap' => null,
        'amount' => null, 'minimum' => null, 'startsAt' => CarbonImmutable::parse('2026-10-01 00:00:00'), 'endsAt' => null,
        'perCustomer' => null, 'total' => null, 'uses' => 0, 'points' => true, 'reduced' => false,
        'retail' => true, 'wholesale' => true, 'public' => true, 'company' => true, 'active' => true, 'rules' => [],
        ...$with,
    ];

    return new Coupon(
        '01k7coupon0000000000000001', '01k7store00000000000000001', 'SUMMER-15', 'صيف', 'Summer',
        $values['eligibility'], $values['kind'], $values['percent'], $values['cap'], $values['amount'], 'SAR',
        $values['minimum'], $values['startsAt'], $values['endsAt'], $values['perCustomer'], $values['total'],
        $values['uses'], $values['points'], $values['reduced'], $values['retail'], $values['wholesale'],
        $values['public'], $values['company'], $values['active'], $values['rules'],
    );
}

/**
 * @param  list<string>  $categories
 */
function line(int $total, string $product = 'p1', string $brand = 'b1', array $categories = ['c-leaf', 'c-top'], bool $wholesale = false, bool $reduced = false): CouponLine
{
    return new CouponLine($product, $brand, $categories, $wholesale, $reduced, sar($total));
}

function rule(RuleKind $kind, RuleTarget $target, string $id): CouponRule
{
    return new CouponRule($kind, $target, $id);
}

/**
 * @param  list<CouponLine>  $lines
 * @return list<int>
 */
function shares(Coupon $coupon, array $lines, Audience $audience = Audience::Public, ?int $net = null): array
{
    $outcome = CouponRules::work($coupon, $lines, $audience, sar($net ?? 1_000_000_00));

    return array_map(static fn (Money $share): int => $share->minorUnits, $outcome->shares);
}

describe('what a coupon takes off (§1.3)', function () {
    it('takes 15% of the eligible lines, capped at 100 SAR', function () {
        $outcome = CouponRules::work(coupon(['cap' => sar(100_00)]), [line(1_000_00)], Audience::Public, sar(1_000_00));
        $small = CouponRules::work(coupon(['cap' => sar(100_00)]), [line(500_00)], Audience::Public, sar(500_00));

        expect($outcome->discount?->minorUnits)->toBe(100_00)
            ->and($small->discount?->minorUnits)->toBe(75_00);
    });

    it('takes a fixed 50 SAR off eligible lines worth 30 SAR as 30', function () {
        $outcome = CouponRules::work(coupon(['kind' => DiscountKind::Fixed, 'percent' => null, 'amount' => sar(50_00)]), [line(30_00), line(90_00, wholesale: true)], Audience::Public, sar(120_00));
        $exact = CouponRules::work(coupon(['kind' => DiscountKind::Fixed, 'percent' => null, 'amount' => sar(50_00)]), [line(80_00)], Audience::Public, sar(80_00));

        // The wholesale line is covered too (both modes by default): 50 off 120.
        expect($outcome->discount?->minorUnits)->toBe(50_00)
            ->and(CouponRules::work(coupon(['kind' => DiscountKind::Fixed, 'percent' => null, 'amount' => sar(50_00), 'wholesale' => false]), [line(30_00), line(90_00, wholesale: true)], Audience::Public, sar(120_00))->discount?->minorUnits)->toBe(30_00)
            ->and($exact->discount?->minorUnits)->toBe(50_00);
    });

    it('rounds half up: 15% of 10.03 is 1.50, 15% of 10.10 (1.515) is 1.52', function () {
        expect(CouponRules::work(coupon(), [line(10_03)], Audience::Public, sar(10_03))->discount?->minorUnits)->toBe(1_50)
            ->and(CouponRules::work(coupon(), [line(10_10)], Audience::Public, sar(10_10))->discount?->minorUnits)->toBe(1_52)
            ->and(CouponRules::work(coupon(['percent' => 100]), [line(10_10)], Audience::Public, sar(10_10))->discount?->minorUnits)->toBe(10_10)
            ->and(CouponRules::work(coupon(['percent' => 5]), [line(10_10)], Audience::Public, sar(10_10))->discount?->minorUnits)->toBe(51);
    });

    it('spreads the discount over the covered lines in proportion, adding up exactly, 0 on the rest', function () {
        $lines = [line(1_00), line(1_00, product: 'other', categories: ['c-other']), line(1_00), line(1_00)];
        $outcome = CouponRules::work(coupon(['kind' => DiscountKind::Fixed, 'percent' => null, 'amount' => sar(1_00), 'rules' => [rule(RuleKind::Include, RuleTarget::Category, 'c-top')]]), $lines, Audience::Public, sar(4_00));

        expect(array_map(static fn (Money $share): int => $share->minorUnits, $outcome->shares))->toBe([34, 0, 33, 33])
            ->and(array_sum(array_map(static fn (Money $share): int => $share->minorUnits, $outcome->shares)))->toBe(1_00)
            ->and(shares(coupon(), [line(200_00), line(100_00)]))->toBe([30_00, 15_00]);
    });
});

describe('which lines a coupon covers (§1.2)', function () {
    it('lands on the eligible lines of a mixed cart and leaves the rest, never refusing the cart', function () {
        $coupon = coupon(['rules' => [rule(RuleKind::Include, RuleTarget::Product, 'p1')]]);

        expect(shares($coupon, [line(100_00), line(100_00, product: 'p2')]))->toBe([15_00, 0]);
    });

    it('reaches a category\'s sub-categories through any category above the product\'s', function () {
        $coupon = coupon(['rules' => [rule(RuleKind::Include, RuleTarget::Category, 'c-top')]]);

        expect(shares($coupon, [line(100_00, categories: ['c-leaf', 'c-mid', 'c-top']), line(100_00, categories: ['c-elsewhere'])]))->toBe([15_00, 0]);
    });

    it('lets an exclusion win: an excluded brand inside an included category is skipped', function () {
        $coupon = coupon(['rules' => [rule(RuleKind::Include, RuleTarget::Category, 'c-top'), rule(RuleKind::Exclude, RuleTarget::Brand, 'brand-x')]]);
        $excludedFirst = coupon(['rules' => [rule(RuleKind::Exclude, RuleTarget::Brand, 'brand-x'), rule(RuleKind::Include, RuleTarget::Category, 'c-top')]]);

        expect(shares($coupon, [line(100_00), line(100_00, brand: 'brand-x')]))->toBe([15_00, 0])
            ->and(shares($excludedFirst, [line(100_00), line(100_00, brand: 'brand-x')]))->toBe([15_00, 0]);
    });

    it('covers every product with no include list, an exclusion list alone narrowing it', function () {
        expect(shares(coupon(), [line(100_00), line(100_00, product: 'p2')]))->toBe([15_00, 15_00])
            ->and(shares(coupon(['rules' => [rule(RuleKind::Exclude, RuleTarget::Product, 'p2')]]), [line(100_00), line(100_00, product: 'p2')]))->toBe([15_00, 0]);
    });

    it('includes by product, by category or by brand, any one of them enough', function () {
        $coupon = coupon(['rules' => [rule(RuleKind::Include, RuleTarget::Product, 'p9'), rule(RuleKind::Include, RuleTarget::Brand, 'b2')]]);

        expect(shares($coupon, [line(100_00), line(100_00, product: 'p9'), line(100_00, brand: 'b2')]))->toBe([0, 15_00, 15_00]);
    });

    it('skips a reduced line by default and covers it when the coupon allows it', function () {
        expect(shares(coupon(), [line(100_00, reduced: true), line(100_00)]))->toBe([0, 15_00])
            ->and(shares(coupon(['reduced' => true]), [line(100_00, reduced: true), line(100_00)]))->toBe([15_00, 15_00]);
    });

    it('respects the sale modes and the buyer the coupon allows', function () {
        expect(shares(coupon(['wholesale' => false]), [line(100_00, wholesale: true), line(100_00)], Audience::Company))->toBe([0, 15_00])
            ->and(shares(coupon(['retail' => false]), [line(100_00, wholesale: true), line(100_00)], Audience::Company))->toBe([15_00, 0])
            ->and(CouponRules::work(coupon(['company' => false]), [line(100_00)], Audience::Company, sar(100_00))->refusal)->toBe(CouponRefusal::NothingEligible)
            ->and(CouponRules::work(coupon(['public' => false]), [line(100_00)], Audience::Public, sar(100_00))->refusal)->toBe(CouponRefusal::NothingEligible)
            ->and(shares(coupon(['public' => false]), [line(100_00)], Audience::Company))->toBe([15_00]);
    });
});

describe('refused whole (§1.3)', function () {
    $now = CarbonImmutable::parse('2026-10-17 12:00:00');

    it('refuses an inactive, a scheduled, an ended, a used-up coupon, and one not assigned to the customer', function (array $with, CouponUsage $usage, CouponRefusal $refusal) use ($now) {
        expect(CouponRules::refusalNow(coupon($with), $usage, $now))->toBe($refusal);
    })->with([
        'inactive' => [['active' => false], new CouponUsage(0, false), CouponRefusal::Inactive],
        'not started' => [['startsAt' => CarbonImmutable::parse('2026-10-17 12:00:01')], new CouponUsage(0, false), CouponRefusal::NotStarted],
        'ended' => [['endsAt' => CarbonImmutable::parse('2026-10-17 12:00:00')], new CouponUsage(0, false), CouponRefusal::Ended],
        'used up' => [['total' => 5, 'uses' => 5], new CouponUsage(0, false), CouponRefusal::UsedUp],
        'used up for you' => [['perCustomer' => 2], new CouponUsage(2, false), CouponRefusal::UsedUpForYou],
        'not assigned' => [['eligibility' => CouponEligibility::Assigned], new CouponUsage(0, false), CouponRefusal::NotAssignedToYou],
    ]);

    it('lets the coupon be used at its start, just before its end, below its limits, and when assigned', function () use ($now) {
        $usable = coupon([
            'startsAt' => $now, 'endsAt' => $now->addSecond(), 'total' => 5, 'uses' => 4, 'perCustomer' => 2,
            'eligibility' => CouponEligibility::Assigned,
        ]);

        expect(CouponRules::refusalNow($usable, new CouponUsage(1, true), $now))->toBeNull()
            ->and(CouponRules::refusalNow(coupon(), new CouponUsage(1_000, false), $now))->toBeNull();
    });

    it('checks in the order the refusals are listed, so an ended and used-up coupon says it ended', function () use ($now) {
        $both = coupon(['endsAt' => $now->subDay(), 'total' => 1, 'uses' => 1]);

        expect(CouponRules::refusalNow($both, new CouponUsage(0, false), $now))->toBe(CouponRefusal::Ended)
            ->and(CouponRules::refusalNow(coupon(['active' => false, 'startsAt' => $now->addDay()]), new CouponUsage(0, false), $now))->toBe(CouponRefusal::Inactive);
    });

    it('refuses an order under the minimum on net_subtotal, and takes one exactly at it', function () {
        expect(CouponRules::work(coupon(['minimum' => sar(200_00)]), [line(300_00)], Audience::Public, sar(199_99))->refusal)->toBe(CouponRefusal::BelowMinimum)
            ->and(CouponRules::work(coupon(['minimum' => sar(200_00)]), [line(300_00)], Audience::Public, sar(200_00))->discount?->minorUnits)->toBe(45_00);
    });

    // §8 said "1% of 50 halalas"; 0.5 rounds half up to 1 (answer 19), so 49 is the example.
    it('refuses a coupon with no line eligible, and one worth nothing (1% of 49 halalas)', function () {
        expect(CouponRules::work(coupon(['rules' => [rule(RuleKind::Include, RuleTarget::Product, 'p9')]]), [line(100_00)], Audience::Public, sar(100_00))->refusal)->toBe(CouponRefusal::NothingEligible)
            ->and(CouponRules::work(coupon(['percent' => 1]), [line(49)], Audience::Public, sar(49))->refusal)->toBe(CouponRefusal::WorthNothing)
            ->and(CouponRules::work(coupon(['percent' => 1]), [line(49)], Audience::Public, sar(49))->discount)->toBeNull()
            ->and(CouponRules::work(coupon(['percent' => 1]), [line(50)], Audience::Public, sar(50))->discount?->minorUnits)->toBe(1)
            ->and(CouponRules::work(coupon(['percent' => 1]), [line(150)], Audience::Public, sar(150))->discount?->minorUnits)->toBe(2);
    });

    it('refuses a coupon that would pass the room under the ceiling, never trimming it', function () use ($now) {
        $usage = new CouponUsage(0, false);

        expect(CouponRules::quote(coupon(), $usage, [line(1_000_00)], Audience::Public, sar(1_000_00), sar(149_99), $now)->refusal)->toBe(CouponRefusal::PassesCeiling)
            ->and(CouponRules::quote(coupon(), $usage, [line(1_000_00)], Audience::Public, sar(1_000_00), sar(150_00), $now)->discount?->minorUnits)->toBe(150_00)
            ->and(CouponRules::quote(coupon(['active' => false]), $usage, [line(1_000_00)], Audience::Public, sar(1_000_00), sar(1_000_00), $now)->refusal)->toBe(CouponRefusal::Inactive)
            ->and(CouponRules::quote(coupon(), $usage, [line(1_000_00)], Audience::Public, sar(1_000_00), sar(0), $now)->refusal)->toBe(CouponRefusal::PassesCeiling);
    });
});

describe('the room under the ceiling (§1.6)', function () {
    it('leaves 10% when prices already took 20% under a 30% ceiling, and none at 35%', function () {
        expect(DiscountRoom::of(sar(1_000_00), sar(800_00), 30)->minorUnits)->toBe(100_00)
            ->and(DiscountRoom::of(sar(1_000_00), sar(650_00), 30)->minorUnits)->toBe(0)
            ->and(DiscountRoom::of(sar(1_000_00), sar(1_000_00), 30)->minorUnits)->toBe(300_00)
            ->and(DiscountRoom::of(sar(1_000_00), sar(1_000_00), 0)->minorUnits)->toBe(0)
            ->and(DiscountRoom::of(sar(1_000_00), sar(1_000_00), 100)->minorUnits)->toBe(1_000_00);
    });

    it('rounds what the ceiling allows down, so the room never passes it', function () {
        // 30% of 10.05 is 3.015: 3.01 allowed.
        expect(DiscountRoom::of(sar(10_05), sar(10_05), 30)->minorUnits)->toBe(3_01)
            ->and(DiscountRoom::of(sar(10_05), sar(10_04), 30)->minorUnits)->toBe(3_00);
    });
});
