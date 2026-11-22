<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use DateTimeImmutable;

/**
 * The life of something dated - a sale, a category discount (pricing.md §4.1): read from its start, its
 * end and the clock, never stored. The class using it has `startsAt` and a nullable `endsAt`.
 */
trait DatedWindow
{
    public function scheduled(DateTimeImmutable $at): bool
    {
        return $this->startsAt > $at;
    }

    public function ended(DateTimeImmutable $at): bool
    {
        return $this->endsAt !== null && $this->endsAt <= $at;
    }

    public function running(DateTimeImmutable $at): bool
    {
        return ! $this->scheduled($at) && ! $this->ended($at);
    }

    /**
     * Its start and its end still to come (§1.10): the moments a timed task pushes its sizes' prices.
     *
     * @return list<DateTimeImmutable>
     */
    public function momentsAfter(DateTimeImmutable $now): array
    {
        return array_values(array_filter([$this->startsAt, $this->endsAt], static fn (?DateTimeImmutable $moment): bool => $moment !== null && $moment > $now));
    }

    /** Whether its start or its end is this moment, to the microsecond. */
    public function startsOrEndsAt(DateTimeImmutable $moment): bool
    {
        return $this->startsAt->format('U.u') === $moment->format('U.u') || $this->endsAt?->format('U.u') === $moment->format('U.u');
    }

    /** Whether its dates meet another window's: both open-ended at the end where an end is null. */
    public function overlaps(DateTimeImmutable $startsAt, ?DateTimeImmutable $endsAt): bool
    {
        return ($endsAt === null || $this->startsAt < $endsAt) && ($this->endsAt === null || $startsAt < $this->endsAt);
    }
}
