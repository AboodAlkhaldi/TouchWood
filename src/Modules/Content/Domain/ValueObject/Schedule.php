<?php

declare(strict_types=1);

namespace Modules\Content\Domain\ValueObject;

use DateTimeImmutable;
use Modules\Content\Domain\Model\ScheduleState;

/**
 * When a banner or a pop-up shows (content.md §1.1 rule 5, §4.1): scheduled or a draft, a start and an end (none:
 * No End). **`SCHEDULED`, `LIVE` and `ENDED` are read from the dates and the clock, never stored**, as a sale's
 * are: a draft is a draft whatever its dates say; a scheduled one is live from its start until its end.
 */
final readonly class Schedule
{
    public function __construct(
        public bool $isScheduled,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
    ) {}

    public function state(DateTimeImmutable $now): ScheduleState
    {
        return match (true) {
            ! $this->isScheduled => ScheduleState::Draft,
            $this->endsAt !== null && $this->endsAt <= $now => ScheduleState::Ended,
            $this->startsAt === null || $this->startsAt <= $now => ScheduleState::Live,
            default => ScheduleState::Scheduled,
        };
    }

    /**
     * Whether two schedules share a moment: a start before the other's end (none: never ending) both ways. A
     * draft shares none (§1.8: "a draft never clashes").
     */
    public function overlaps(self $other): bool
    {
        if (! $this->isScheduled || ! $other->isScheduled || $this->startsAt === null || $other->startsAt === null) {
            return false;
        }

        return ($other->endsAt === null || $this->startsAt < $other->endsAt)
            && ($this->endsAt === null || $other->startsAt < $this->endsAt);
    }

    /** Whether it shows at that moment on a page that reads it: live then. */
    public function showsAt(DateTimeImmutable $now): bool
    {
        return $this->state($now) === ScheduleState::Live;
    }
}
