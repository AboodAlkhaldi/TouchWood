<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Service;

use Modules\Loyalty\Domain\Model\Programme;
use Modules\Loyalty\Domain\Model\Redemption;
use Modules\Loyalty\Public\Enums\RedemptionMode;
use Modules\Loyalty\Public\Enums\RedemptionRefusal;

/**
 * Works a redemption out (loyalty.md §1.7), in the spec's order: the programme on and points there; the
 * points asked; the cap; the discount and the points charged for it; the minimum. Pure: the same
 * answer for a quote and, again, at placement.
 */
final class RedemptionRules
{
    /**
     * @param  int  $balance  the account's live points now
     * @param  int|null  $asked  the points asked for; null: as many as allowed. Not read in
     *                           ALL_OR_NOTHING, where the whole balance is asked
     */
    public static function work(Programme $programme, RedemptionMode $mode, int $balance, ?int $asked, int $netSubtotalMinor): Redemption
    {
        if (! $programme->enabled) {
            return Redemption::refused($mode, 0, RedemptionRefusal::ProgrammeOff);
        }

        if ($balance < 1) {
            return Redemption::refused($mode, 0, RedemptionRefusal::NothingToRedeem);
        }

        $ppu = $programme->pointsPerUnitOff;
        $exponent = $programme->exponent;
        $underCap = PointsArithmetic::mostPointsUnderCap(
            PointsArithmetic::cap($netSubtotalMinor, $programme->maxRedemptionPercent), $ppu, $exponent,
        );
        // Points the cap lets count but that buy nothing are not usable: a cap of 0 says 0, not 1.
        $nothingUnderCap = PointsArithmetic::worth($underCap, $ppu, $exponent) === 0;
        $mostUsable = $nothingUnderCap ? 0 : min($balance, $underCap);

        if ($mode === RedemptionMode::Partial && $asked !== null && ($asked < 1 || $asked > $balance)) {
            return Redemption::refused($mode, $mostUsable, RedemptionRefusal::OutOfRange);
        }

        if ($nothingUnderCap) {
            return Redemption::refused($mode, $mostUsable, RedemptionRefusal::NothingUnderCap);
        }

        // Points asked beyond the cap are not used, and stay — in both modes (§1.7 step 3).
        $ask = $mode === RedemptionMode::Partial ? ($asked ?? $balance) : $balance;
        $discount = PointsArithmetic::worth(min($ask, $underCap), $ppu, $exponent);

        if ($discount === 0) {
            return Redemption::refused($mode, $mostUsable, RedemptionRefusal::WorthNothing);
        }

        // The fewest points worth the discount: never a point that bought nothing (§1.5).
        $charged = PointsArithmetic::pointsCharged($discount, $ppu, $exponent);

        if ($charged < $programme->minimumRedemption) {
            return Redemption::refused($mode, $mostUsable, RedemptionRefusal::BelowMinimum);
        }

        return new Redemption($mode, $mostUsable, $charged, $discount, null);
    }
}
