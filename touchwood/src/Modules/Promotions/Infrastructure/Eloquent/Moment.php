<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure\Eloquent;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A moment as PostgreSQL is given it: in UTC, with its fraction of a second and its offset. Laravel's
 * own date format drops both, so a time given in +03:00 would be stored three hours late (Promotions
 * step 3b's review) — as Loyalty's and Pricing's writes already guard against.
 */
final class Moment
{
    public static function of(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
