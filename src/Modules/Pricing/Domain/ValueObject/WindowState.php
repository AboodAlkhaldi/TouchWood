<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Where a sale or a category discount is in its life (pricing.md §4.1), read from its dates and the
 * clock, never stored: the screens' badge and their state filter (§4.3.3 P2, P3).
 */
enum WindowState: string
{
    case Scheduled = 'SCHEDULED';
    case Running = 'RUNNING';
    case Ended = 'ENDED';

    public static function of(DateTimeImmutable $startsAt, ?DateTimeImmutable $endsAt, DateTimeImmutable $now): self
    {
        if ($startsAt > $now) {
            return self::Scheduled;
        }

        return $endsAt !== null && $endsAt <= $now ? self::Ended : self::Running;
    }
}
