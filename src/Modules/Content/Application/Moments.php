<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Platform\Public\Dto\StoreDto;
use Throwable;

/**
 * Moments as staff type and read them (content.md §1.1 rule 6): **in the store's own time zone**, to the minute
 * (`2027-02-18 00:00`, or with the form's `T`); the database keeps UTC. A date that does not exist (February 30th)
 * is refused rather than rolled over.
 */
final class Moments
{
    /**
     * @throws InvalidContentInput
     */
    public static function typed(string $attribute, ?string $value, StoreDto $store): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = str_replace('T', ' ', trim($value));

        try {
            $at = preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}\z/', $value) === 1
                ? CarbonImmutable::createFromFormat('!Y-m-d H:i', $value, $store->timezone)
                : null;
        } catch (Throwable) {
            $at = null;
        }

        if (! $at instanceof CarbonImmutable || $at->format('Y-m-d H:i') !== $value) {
            throw new InvalidContentInput($attribute, InvalidContentInput::DATE);
        }

        return $at->utc()->toDateTimeImmutable();
    }

    /** A moment as the screen shows it, in the store's zone. */
    public static function shown(DateTimeImmutable $at, StoreDto $store): string
    {
        return CarbonImmutable::instance($at)->setTimezone($store->timezone)->format('Y-m-d H:i');
    }

    /** Now, on the frozen clock in tests, to the second the database keeps (lesson 223). */
    public static function now(): DateTimeImmutable
    {
        return CarbonImmutable::now()->utc()->startOfSecond()->toDateTimeImmutable();
    }

    /** End Now's end: this moment, or a second after a start that is this very moment, the end being after the start. */
    public static function endNow(?DateTimeImmutable $startsAt, DateTimeImmutable $now): DateTimeImmutable
    {
        return $startsAt !== null && $startsAt >= $now ? $startsAt->modify('+1 second') : $now;
    }

    /**
     * Start and end, refused when the end is not after the start.
     *
     * @return array{0: DateTimeImmutable|null, 1: DateTimeImmutable|null}
     *
     * @throws InvalidContentInput
     */
    public static function range(?string $starts, ?string $ends, StoreDto $store): array
    {
        $from = self::typed('starts_at', $starts, $store);
        $to = self::typed('ends_at', $ends, $store);

        if ($from !== null && $to !== null && $to <= $from) {
            throw new InvalidContentInput('ends_at', InvalidContentInput::DATES);
        }

        return [$from, $to];
    }
}
