<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Service;

use DateTimeImmutable;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Modules\Pricing\Domain\ValueObject\ResolvedPrice;
use Modules\Pricing\Public\Enums\PriceKind;

/**
 * What a line costs - a size, its sale mode, a quantity - at a moment (pricing.md §1.6). Pure: it reads
 * only the candidates it is given (the materialized ones, §5), so no category, percentage or rounding
 * is worked out at request time (handoff §10.1).
 *
 * 1. No retail price, no price.
 * 2. The candidates: the retail price, every sale and category discount running, and on a wholesale
 *    line the band with the highest start at or below the quantity.
 * 3. An "always wins" one running is the price, every other sale and discount set aside - on a
 *    wholesale line the lower of it and the band (owner, 2026-10-08/09). Above the retail price it has
 *    "simply stopped winning" (§1.3) and pauses nothing: the others count again (owner, 2026-10-10).
 * 4. Otherwise the cheapest wins. Equal ones: the dated first - sale, then category discount - then the
 *    band, then the retail price, so the shopper sees why it is cheaper (owner, 2026-10-08); equal in
 *    amount and kind, the one lasting longest (owner, 2026-10-10).
 * 5. The list price: the retail price; on a wholesale line its band where one applies - a wholesale
 *    price is not a discount - but never above the retail price (owner, 2026-10-10).
 * 6. It stops applying at the winner's end, or sooner at the start of an "always wins" one scheduled
 *    on the line, which would replace it.
 */
final class PriceResolution
{
    /**
     * Ties between equal prices: the lower comes first. Campaign prices join in stage 8; their place
     * among the dated ones is decided then.
     */
    private const array RANK = [
        'SALE' => 0,
        'CATEGORY' => 1,
        'CAMPAIGN' => 2,
        'QUANTITY' => 3,
        'BASE' => 4,
    ];

    /**
     * @param  list<Candidate>  $candidates  the size's candidates in the store (any mode)
     */
    public static function resolve(array $candidates, SaleMode $mode, int $quantity, DateTimeImmutable $at): ?ResolvedPrice
    {
        $forLine = array_values(array_filter($candidates, static fn (Candidate $candidate): bool => $candidate->mode === $mode));

        $base = null;
        $band = null;
        $running = [];
        $scheduledAlwaysWins = [];

        foreach ($forLine as $candidate) {
            if ($candidate->kind === PriceKind::Base) {
                $base ??= $candidate;
            } elseif ($candidate->kind === PriceKind::Quantity) {
                if ($mode === SaleMode::Wholesale && $candidate->fromQuantity <= $quantity
                    && ($band === null || $candidate->fromQuantity > $band->fromQuantity)) {
                    $band = $candidate;
                }
            } elseif ($candidate->runningAt($at)) {
                $running[] = $candidate;
            } elseif ($candidate->alwaysWins && $candidate->startsAfter($at)) {
                $scheduledAlwaysWins[] = $candidate;
            }
        }

        if ($base === null) {
            return null;
        }

        $alwaysWins = array_values(array_filter($running, static fn (Candidate $candidate): bool => $candidate->alwaysWins));
        $leading = $alwaysWins === [] ? null : self::cheapest($alwaysWins);
        $bands = $band === null ? [] : [$band];

        if ($leading !== null && $leading->amount->compareTo($base->amount) <= 0) {
            // While it is the price, every other sale and discount is set aside; on a wholesale line
            // it never beats a cheaper band.
            $winner = self::cheapest([$leading, ...$bands]);
        } else {
            // None running, or one above the retail price, which has "simply stopped winning" (§1.3):
            // it no longer pauses the others, and the cheapest wins (owner, 2026-10-10).
            $winner = self::cheapest([$base, ...$bands, ...$running]);
        }

        // A wholesale line's normal price is its band - a wholesale price is not a discount (owner,
        // 2026-10-10) - but never above the retail price, so a band left above a lowered retail price
        // never shows as a reduction (owner, 2026-10-10).
        $listUnit = $band !== null && $band->amount->compareTo($base->amount) < 0 ? $band->amount : $base->amount;

        return new ResolvedPrice(
            listUnit: $listUnit,
            unit: $winner->amount,
            kind: $winner->kind,
            endsAt: self::earliest([$winner->endsAt, ...array_map(static fn (Candidate $candidate): ?DateTimeImmutable => $candidate->startsAt, $scheduledAlwaysWins)]),
        );
    }

    /**
     * The cheapest; equal amounts by rank; equal amount and kind, the one lasting longest (no end
     * lasts longest), so a quote built on it is valid for as long as it can be.
     *
     * @param  non-empty-list<Candidate>  $candidates
     */
    private static function cheapest(array $candidates): Candidate
    {
        usort($candidates, static function (Candidate $a, Candidate $b): int {
            return $a->amount->compareTo($b->amount)
                ?: self::RANK[$a->kind->value] <=> self::RANK[$b->kind->value]
                ?: self::endsLater($b, $a);
        });

        return $candidates[0];
    }

    /** Positive when $a ends after $b; a candidate with no end ends after every dated one. */
    private static function endsLater(Candidate $a, Candidate $b): int
    {
        if ($a->endsAt === null || $b->endsAt === null) {
            return ($a->endsAt === null ? 1 : 0) - ($b->endsAt === null ? 1 : 0);
        }

        return $a->endsAt <=> $b->endsAt;
    }

    /**
     * @param  list<DateTimeImmutable|null>  $moments
     */
    private static function earliest(array $moments): ?DateTimeImmutable
    {
        $known = array_values(array_filter($moments, static fn (?DateTimeImmutable $moment): bool => $moment !== null));

        return $known === [] ? null : min($known);
    }
}
