<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;

/**
 * Every Ops listener runs once per event (ops.md §5, §6: `ops.handled_events`), as Shared's
 * ProcessedEvents does for the outbox's critical listeners: the event's id and the listener's name are
 * written in the same transaction as the work, under their key. A second arrival finds them and does
 * nothing; two at once wait for each other, and the second finds them too.
 */
final readonly class HandledEvents
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    /**
     * Runs the work once for this event and listener; true when it ran now.
     *
     * @param  Closure(): void  $work
     */
    public function once(string $eventId, string $listener, Closure $work): bool
    {
        return $this->db->transaction(function () use ($eventId, $listener, $work): bool {
            $taken = $this->db->table('ops.handled_events')->insertOrIgnore([
                'event_id' => strtolower($eventId),
                'listener' => $listener,
                'handled_at' => Moment::of(CarbonImmutable::now()->toDateTimeImmutable()),
            ]);

            if ($taken === 0) {
                return false;
            }

            $work();

            return true;
        });
    }
}
