<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Domain\Model\AttemptState;

/**
 * An attempt's own few checks (payments.md §1.1 rule 9; owner, 2026-10-30, gap 10.4): the end of the window, the
 * end of the wait and the hold limit, then 10 and 20 minutes after the charge, then each hour for 24 hours, so a
 * late success is always found. They stop at an answer: when nobody is paying, nothing runs.
 */
final class CheckSchedule
{
    public const int LAST_HOUR = 24;

    public const int ATTENTION_MINUTES = 20;

    public static function next(Attempt $attempt, DateTimeImmutable $now): ?DateTimeImmutable
    {
        // Still waiting, or released at the hold limit and still to be found if it succeeds late.
        if (! $attempt->state->isLive() && $attempt->state !== AttemptState::Creating && ! ($attempt->state === AttemptState::Expired && ! $attempt->nothingCharged)) {
            return null;
        }

        $points = [$attempt->windowEndsAt, $attempt->waitEndsAt, $attempt->holdEndsAt];

        if ($attempt->chargeClaimedAt !== null) {
            $charged = CarbonImmutable::instance($attempt->chargeClaimedAt);
            $points[] = $charged->addMinutes(10)->toDateTimeImmutable();
            $points[] = $charged->addMinutes(self::ATTENTION_MINUTES)->toDateTimeImmutable();

            foreach (range(1, self::LAST_HOUR) as $hour) {
                $points[] = $charged->addHours($hour)->toDateTimeImmutable();
            }
        }

        $later = array_filter($points, static fn (DateTimeImmutable $point): bool => $point > $now);

        return $later === [] ? null : min($later);
    }
}
