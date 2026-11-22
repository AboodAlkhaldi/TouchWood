<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Outbox;

use Closure;
use Illuminate\Database\ConnectionInterface;

/**
 * The idempotent consumer (handoff §4.5; platform.md §5.6): a listener of a critical event does its work
 * **once per event**, however often the event arrives — the outbox delivers at least once. The event's
 * id and the listener's name are written in the same transaction as the work, under their key: a second
 * arrival finds them and does nothing; two at once wait for each other, and the second finds them too.
 */
final readonly class ProcessedEvents
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
            $taken = $this->db->table('public.processed_events')->insertOrIgnore([
                'event_id' => strtolower($eventId),
                'listener' => $listener,
                'processed_at' => now(),
            ]);

            if ($taken === 0) {
                return false;
            }

            $work();

            return true;
        });
    }
}
