<?php

declare(strict_types=1);

use Modules\Loyalty\Domain\Model\Programme;
use Modules\Loyalty\Domain\Model\Redemption;
use Modules\Loyalty\Domain\Service\PointsArithmetic;
use Modules\Loyalty\Domain\Service\RedemptionRules;
use Modules\Loyalty\Public\Enums\Audience;
use Modules\Loyalty\Public\Enums\RedemptionMode;
use Modules\Loyalty\Public\Enums\RedemptionRefusal;

/*
| A redemption worked out (loyalty.md §1.7), in the spec's order, with the cap and the minimum; the
| same rules for a quote and for placing it.
*/

/** The spec's defaults, on: 100 points a unit off, 50% cap, no minimum, both audiences partial. */
function loyaltyRulesProgramme(int $ppu = 100, int $percent = 50, int $minimum = 0, bool $enabled = true, bool $publicPartial = true, bool $companyPartial = true, int $exponent = 2): Programme
{
    return new Programme($enabled, 1, $ppu, 12, $minimum, $percent, $publicPartial, $companyPartial, 'SAR', $exponent);
}

function loyaltyRules(Programme $programme, int $balance, ?int $asked, int $net, RedemptionMode $mode = RedemptionMode::Partial): Redemption
{
    return RedemptionRules::work($programme, $mode, $balance, $asked, $net);
}

it('uses the chosen points in PARTIAL, and the whole balance in ALL_OR_NOTHING', function () {
    $partial = loyaltyRules(loyaltyRulesProgramme(), 500, 200, 100_000);
    $all = loyaltyRules(loyaltyRulesProgramme(), 500, 200, 100_000, RedemptionMode::AllOrNothing);

    expect([$partial->pointsCharged, $partial->discountMinor, $partial->mostUsable, $partial->refusal])->toBe([200, 200, 500, null])
        // The ask is not read: the whole balance or none.
        ->and([$all->pointsCharged, $all->discountMinor, $all->refusal])->toBe([500, 500, null])
        // PARTIAL with nothing asked: as many as allowed.
        ->and(loyaltyRules(loyaltyRulesProgramme(), 500, null, 100_000)->pointsCharged)->toBe(500);
});

it('reads each audience\'s own switch', function () {
    $programme = loyaltyRulesProgramme(publicPartial: true, companyPartial: false);

    expect($programme->modeFor(Audience::Public))->toBe(RedemptionMode::Partial)
        ->and($programme->modeFor(Audience::Company))->toBe(RedemptionMode::AllOrNothing)
        ->and(loyaltyRulesProgramme(publicPartial: false, companyPartial: true)->modeFor(Audience::Public))->toBe(RedemptionMode::AllOrNothing)
        ->and(loyaltyRulesProgramme(publicPartial: false, companyPartial: true)->modeFor(Audience::Company))->toBe(RedemptionMode::Partial);
});

it('uses points up to the cap and keeps the rest, in both modes', function (RedemptionMode $mode) {
    // 100.00 at 50%: 50.00 at most, 5,000 points at 100 a unit; 3,000 of the 8,000 stay.
    $redemption = loyaltyRules(loyaltyRulesProgramme(), 8_000, $mode === RedemptionMode::Partial ? 8_000 : null, 10_000, $mode);

    expect([$redemption->pointsCharged, $redemption->discountMinor, $redemption->mostUsable])->toBe([5_000, 5_000, 5_000]);
})->with([RedemptionMode::Partial, RedemptionMode::AllOrNothing]);

it('never lets the discount pass the cap, and finds the largest count that fits', function (int $ppu, int $net, int $percent) {
    $cap = PointsArithmetic::cap($net, $percent);

    foreach ([1, 7, 149, 150, 151, 10_000, 1_000_000] as $balance) {
        $redemption = loyaltyRules(loyaltyRulesProgramme(ppu: $ppu, percent: $percent), $balance, null, $net);

        if ($redemption->refusal !== null) {
            continue;
        }

        expect($redemption->discountMinor)->toBeLessThanOrEqual($cap)
            ->and($redemption->pointsCharged)->toBeLessThanOrEqual($balance)
            ->and(PointsArithmetic::worth($redemption->pointsCharged, $ppu, 2))->toBe($redemption->discountMinor)
            // Never a point that bought nothing.
            ->and(PointsArithmetic::worth($redemption->pointsCharged - 1, $ppu, 2))->toBeLessThan($redemption->discountMinor);
    }
})->with([[3, 999, 50], [100, 10_000, 50], [150, 1_234, 37], [1_000, 50_001, 100]]);

it('charges 3 points for 4 asked at 150 a unit: the fewest worth the 2 halalas they buy', function () {
    $redemption = loyaltyRules(loyaltyRulesProgramme(ppu: 150), 100, 4, 100_000);

    expect([$redemption->pointsCharged, $redemption->discountMinor])->toBe([3, 2]);
});

it('refuses with the first rule that fails, charging nothing', function (Programme $programme, int $balance, ?int $asked, int $net, RedemptionRefusal $refusal, int $mostUsable) {
    $redemption = loyaltyRules($programme, $balance, $asked, $net);

    expect($redemption->refusal)->toBe($refusal)
        ->and($redemption->pointsCharged)->toBe(0)
        ->and($redemption->discountMinor)->toBe(0)
        ->and($redemption->mostUsable)->toBe($mostUsable);
})->with([
    'the programme off' => [loyaltyRulesProgramme(enabled: false), 500, 100, 100_000, RedemptionRefusal::ProgrammeOff, 0],
    'no points' => [loyaltyRulesProgramme(), 0, null, 100_000, RedemptionRefusal::NothingToRedeem, 0],
    'an ask of 0' => [loyaltyRulesProgramme(), 500, 0, 100_000, RedemptionRefusal::OutOfRange, 500],
    'an ask above the balance' => [loyaltyRulesProgramme(), 500, 501, 100_000, RedemptionRefusal::OutOfRange, 500],
    'a cap of 0%' => [loyaltyRulesProgramme(percent: 0), 500, 100, 100_000, RedemptionRefusal::NothingUnderCap, 0],
    // The cap lets 1 point count at 150 a unit, but it buys nothing: 0 usable, not 1.
    'a cap of 0% at 150 a unit' => [loyaltyRulesProgramme(ppu: 150, percent: 0), 500, 100, 100_000, RedemptionRefusal::NothingUnderCap, 0],
    // 1 halala of cap at 1 point a unit: one point is worth 100 halalas, so nothing fits.
    'a cap below a point\'s worth' => [loyaltyRulesProgramme(ppu: 1), 500, 1, 2, RedemptionRefusal::NothingUnderCap, 0],
    'one point at 150 a unit' => [loyaltyRulesProgramme(ppu: 150), 500, 1, 100_000, RedemptionRefusal::WorthNothing, 500],
    'below the minimum' => [loyaltyRulesProgramme(minimum: 201), 500, 200, 100_000, RedemptionRefusal::BelowMinimum, 500],
]);

it('measures the minimum on the points charged, not the points asked', function () {
    // 4 asked at 150 a unit charge 3: a minimum of 4 refuses, a minimum of 3 does not.
    expect(loyaltyRules(loyaltyRulesProgramme(ppu: 150, minimum: 4), 100, 4, 100_000)->refusal)->toBe(RedemptionRefusal::BelowMinimum)
        ->and(loyaltyRules(loyaltyRulesProgramme(ppu: 150, minimum: 3), 100, 4, 100_000)->pointsCharged)->toBe(3);
});

it('works out again to the same points and discount when nothing changed', function (int $ppu, int $asked) {
    $programme = loyaltyRulesProgramme(ppu: $ppu);
    $quote = loyaltyRules($programme, 10_000, $asked, 100_000);
    // At placement a PARTIAL redemption is worked out again on the points the quote charged.
    $again = loyaltyRules($programme, 10_000, $quote->pointsCharged, 100_000);

    expect([$again->pointsCharged, $again->discountMinor])->toBe([$quote->pointsCharged, $quote->discountMinor]);
})->with([[3, 7], [100, 250], [150, 4], [150, 1_001], [1_000, 9_999]]);
