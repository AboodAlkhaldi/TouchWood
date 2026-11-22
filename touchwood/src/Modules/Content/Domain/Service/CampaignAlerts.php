<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Service;

use DateTimeImmutable;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;

/**
 * The ending alerts' table (content.md §1.7 #11; round 4 #10). **Length** is the run's end less its start as scheduled
 * (the end as it is now), not the time left:
 *
 * - **72 hours**: for a length of 3 days or more (exactly 3 included), once 72 hours or less are left **and more than
 *   24 hours are still left** - so exactly 3 days sends it as it goes live, and one going live late (the job down, the
 *   store off) or whose end moved past its moment sends it at once only while more than 24 hours are left;
 * - **24 hours**: for a length of 24 hours or more (exactly 24 included), once 24 hours or less are left;
 * - **under 24 hours**: none; ended or cancelled: never; a store that is off: held, then decided as above when it is
 *   back (the job asks only of a store that is on).
 *
 * Each is stamped once (`warned_72_at`, `warned_24_at`); a moved end whose moment is ahead again clears the stamp, so
 * it is sent again when it comes; one passed and sent is not sent again (`afterEndMoved`).
 */
final class CampaignAlerts
{
    public const int HOURS_72 = 72;

    public const int HOURS_24 = 24;

    private const int HOUR = 3600;

    /**
     * The alerts due now for a live run, at most one: 72 or 24.
     *
     * @return list<int>
     */
    public static function due(CampaignRun $run, DateTimeImmutable $now): array
    {
        if ($run->state !== RunState::Live || $run->endsAt <= $now) {
            return [];
        }

        $length = $run->endsAt->getTimestamp() - $run->startsAt->getTimestamp();
        $left = $run->endsAt->getTimestamp() - $now->getTimestamp();

        if ($run->warned72At === null && $length >= self::HOURS_72 * self::HOUR && $left <= self::HOURS_72 * self::HOUR && $left > self::HOURS_24 * self::HOUR) {
            return [self::HOURS_72];
        }

        if ($run->warned24At === null && $length >= self::HOURS_24 * self::HOUR && $left <= self::HOURS_24 * self::HOUR) {
            return [self::HOURS_24];
        }

        return [];
    }

    /**
     * The stamps once the end moves (#11's last rows): an alert whose moment is ahead again is cleared, so it is sent
     * again when it comes; one whose moment has passed keeps its stamp - sent, it is not sent again; unsent, `due`
     * decides it at once.
     *
     * @return array{0: DateTimeImmutable|null, 1: DateTimeImmutable|null} the 72-hour and 24-hour stamps
     */
    public static function afterEndMoved(CampaignRun $run, DateTimeImmutable $newEnd, DateTimeImmutable $now): array
    {
        $ahead = static fn (int $hours): bool => $newEnd->getTimestamp() - $hours * self::HOUR > $now->getTimestamp();

        return [
            $run->warned72At !== null && $ahead(self::HOURS_72) ? null : $run->warned72At,
            $run->warned24At !== null && $ahead(self::HOURS_24) ? null : $run->warned24At,
        ];
    }
}
