<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/** The few conversions every Content table needs between PHP and its columns. */
final class Rows
{
    /** A moment as a `timestamptz` takes it, in UTC. */
    public static function at(?DateTimeImmutable $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->format('Y-m-d H:i:sP');
    }

    /** A `timestamptz` as read back. */
    public static function moment(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->utc()->toDateTimeImmutable();
    }

    public static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** JSON as a `jsonb` takes it, Arabic left readable; an empty map stays a map (lesson 62). */
    public static function json(mixed $value, bool $object = false): string
    {
        return json_encode($object && $value === [] ? (object) [] : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function decode(mixed $value): array
    {
        $decoded = json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
