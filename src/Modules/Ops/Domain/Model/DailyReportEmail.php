<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Domain\ValueObject\StoreId;

/**
 * A person's daily report by email (ops.md §1.11): on or off, and the time it goes, `HH:MM` read in the
 * store's time zone - every store's in the base store's. One per person per store, or one for all
 * stores (owner, 2026-10-28, §9.3 #15). `version` grows with every change: an email queued for an older
 * one stops. `nextDueAt` is the one moment the email may go next, set while it is on.
 */
final readonly class DailyReportEmail
{
    public const string TIME = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    /** @throws InvalidOpsInput */
    public function __construct(
        public string $id,
        public string $staffId,
        /** Null: the report of every store. */
        public ?StoreId $store,
        public bool $isOn,
        public string $sendAt,
        public int $version,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $nextDueAt = null,
    ) {
        if (preg_match(self::TIME, $sendAt) !== 1) {
            throw new InvalidOpsInput('send_at', 'a time of day, HH:MM');
        }

        if ($version < 1) {
            throw new InvalidOpsInput('version', 'from 1');
        }

        if (($nextDueAt !== null) !== $isOn) {
            throw new InvalidOpsInput('next_due_at', 'set while on, and only then');
        }
    }

    /**
     * The next moment it goes after `$after`: today at the time in the zone if still ahead, else
     * tomorrow's - the clock's own time, where a day has 23 or 25 hours.
     */
    public function nextAfter(DateTimeImmutable $after, DateTimeZone $zone): DateTimeImmutable
    {
        return self::nextAt($this->sendAt, $after, $zone);
    }

    /**
     * As nextAfter(), for a time not yet saved.
     *
     * @throws InvalidOpsInput
     */
    public static function nextAt(string $sendAt, DateTimeImmutable $after, DateTimeZone $zone): DateTimeImmutable
    {
        if (preg_match(self::TIME, $sendAt) !== 1) {
            throw new InvalidOpsInput('send_at', 'a time of day, HH:MM');
        }
        [$hour, $minute] = array_map(intval(...), explode(':', $sendAt));
        $local = $after->setTimezone($zone);
        $today = $local->setTime($hour, $minute);

        return $today > $local ? $today : $local->modify('+1 day')->setTime($hour, $minute);
    }
}
