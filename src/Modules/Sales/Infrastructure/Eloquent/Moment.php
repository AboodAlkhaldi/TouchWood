<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A moment as PostgreSQL is given it: in UTC, with its fraction of a second and its offset — Laravel's
 * own date format drops both — as Feedback's, Promotions', Loyalty's and Pricing's writes do.
 */
final class Moment
{
    public static function of(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
