<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure\Eloquent;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A moment as PostgreSQL reads it: in UTC, with its offset and microseconds. Laravel binds a
 * DateTimeInterface as 'Y-m-d H:i:s', which drops both — so a moment given in Riyadh's time would be
 * read as UTC, three hours off.
 */
final class Moment
{
    public static function of(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
