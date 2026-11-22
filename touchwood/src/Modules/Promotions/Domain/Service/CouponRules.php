<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Service;

use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Domain\Model\CouponLine;
use Modules\Promotions\Domain\Model\CouponOutcome;
use Modules\Promotions\Domain\Model\CouponUsage;
use Modules\Promotions\Domain\Model\RuleKind;
use Modules\Promotions\Domain\Model\RuleTarget;
use Modules\Promotions\Public\Enums\Audience;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Modules\Promotions\Public\Enums\DiscountKind;
use Shared\Domain\ValueObject\Money;

/**
 * The coupon's rules (promotions.md §1.2, §1.3), pure: which lines a coupon covers, what it takes off
 * them and how that is spread, and when it is refused — whole, never trimmed. Amounts are in the store
 * currency's smallest unit; a line's total is Pricing's, without VAT.
 */
final class CouponRules
{
    /**
     * A quote (§1.3): whether the coupon may be used now by this customer, then what it takes off, then
     * whether that fits the room left under the store's ceiling (§1.6).
     *
     * @param  list<CouponLine>  $lines
     */
    public static function quote(Coupon $coupon, CouponUsage $usage, array $lines, Audience $audience, Money $netSubtotal, Money $room, DateTimeImmutable $now): CouponOutcome
    {
        $refusal = self::refusalNow($coupon, $usage, $now);

        if ($refusal !== null) {
            return CouponOutcome::refused($refusal);
        }

        $outcome = self::work($coupon, $lines, $audience, $netSubtotal);

        if ($outcome->discount !== null && $outcome->discount->compareTo($room) > 0) {
            return CouponOutcome::refused(CouponRefusal::PassesCeiling);
        }

        return $outcome;
    }

    /**
     * Whether the coupon may be used now by this customer (§1.3) — the assignment first, so nothing
     * else about a coupon given to others is told (answer 29), then its switch, dates, and uses for
     * everyone and for them; null when it may.
     */
    public static function refusalNow(Coupon $coupon, CouponUsage $usage, DateTimeImmutable $now): ?CouponRefusal
    {
        return match (true) {
            $coupon->eligibility === CouponEligibility::Assigned && ! $usage->assigned => CouponRefusal::NotAssignedToYou,
            ! $coupon->active => CouponRefusal::Inactive,
            $now < $coupon->startsAt => CouponRefusal::NotStarted,
            $coupon->endsAt !== null && $now >= $coupon->endsAt => CouponRefusal::Ended,
            // A use held for an online payment counts while it lasts (§9.5), so two buyers never both take
            // the last one.
            $coupon->maxUsesTotal !== null && $coupon->usesTotal + $usage->heldTotal >= $coupon->maxUsesTotal => CouponRefusal::UsedUp,
            $coupon->maxUsesPerCustomer !== null && $usage->usesByCustomer + $usage->heldByCustomer >= $coupon->maxUsesPerCustomer => CouponRefusal::UsedUpForYou,
            default => null,
        };
    }

    /**
     * What the coupon takes off these lines, whatever its dates, switch, uses and assignment (§1.3) —
     * also what a staff edit works out again — or why it takes nothing: the order under its minimum,
     * no line covered, or a discount that rounds to nothing.
     *
     * @param  list<CouponLine>  $lines
     */
    public static function work(Coupon $coupon, array $lines, Audience $audience, Money $netSubtotal): CouponOutcome
    {
        if ($coupon->minimum !== null && $netSubtotal->compareTo($coupon->minimum) < 0) {
            return CouponOutcome::refused(CouponRefusal::BelowMinimum);
        }

        $covered = array_map(static fn (CouponLine $line): bool => self::covers($coupon, $line, $audience), $lines);

        if (! in_array(true, $covered, true)) {
            return CouponOutcome::refused(CouponRefusal::NothingEligible);
        }

        $eligibleTotal = Money::zero($coupon->currencyCode);

        foreach ($lines as $index => $line) {
            if ($covered[$index]) {
                $eligibleTotal = $eligibleTotal->add($line->total);
            }
        }

        $discount = self::amountOff($coupon, $eligibleTotal);

        if (! $discount->isPositive()) {
            return CouponOutcome::refused(CouponRefusal::WorthNothing);
        }

        return CouponOutcome::taken($discount, self::spread($discount, $lines, $covered));
    }

    /**
     * Whether the coupon covers the line (§1.2): included — by the product, its category or any above
     * it, or its brand; no include list covers every product — and not excluded the same ways, as
     * exclusions win; its sale mode and the buyer allowed; not already reduced unless the coupon allows it.
     */
    public static function covers(Coupon $coupon, CouponLine $line, Audience $audience): bool
    {
        if (! ($line->wholesale ? $coupon->forWholesale : $coupon->forRetail)
            || ! ($audience === Audience::Company ? $coupon->forCompany : $coupon->forPublic)
            || ($line->reduced && ! $coupon->onReducedLines)) {
            return false;
        }

        $included = null;

        foreach ($coupon->rules as $rule) {
            $names = match ($rule->target) {
                RuleTarget::Product => $rule->targetId === $line->productId,
                RuleTarget::Brand => $rule->targetId === $line->brandId,
                RuleTarget::Category => in_array($rule->targetId, $line->categoryIds, true),
            };

            if ($rule->kind === RuleKind::Exclude && $names) {
                return false;
            }

            if ($rule->kind === RuleKind::Include) {
                $included = ($included ?? false) || $names;
            }
        }

        return $included ?? true;
    }

    /**
     * A percentage of the eligible total, rounded half up as prices are (owner, 2026-10-10), then at
     * most the cap; or the fixed amount, at most the eligible total.
     */
    private static function amountOff(Coupon $coupon, Money $eligibleTotal): Money
    {
        if ($coupon->discountKind === DiscountKind::Percent) {
            $off = $eligibleTotal->multiply(Percent::fraction((int) $coupon->percent), RoundingMode::HalfUp);

            return $coupon->cap !== null && $off->compareTo($coupon->cap) > 0 ? $coupon->cap : $off;
        }

        $amount = $coupon->amount ?? Money::zero($coupon->currencyCode);

        return $amount->compareTo($eligibleTotal) > 0 ? $eligibleTotal : $amount;
    }

    /**
     * The discount over the covered lines in proportion to their totals, no unit lost (handoff §5.1), so
     * each line knows its share for returns; 0 for a line not covered.
     *
     * @param  list<CouponLine>  $lines
     * @param  list<bool>  $covered
     * @return list<Money>
     */
    private static function spread(Money $discount, array $lines, array $covered): array
    {
        return $discount->allocate(...array_map(
            static fn (CouponLine $line, bool $isCovered): int => $isCovered ? $line->total->minorUnits : 0,
            $lines,
            $covered,
        ));
    }
}
