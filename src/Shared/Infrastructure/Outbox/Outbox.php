<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Outbox;

use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

/**
 * The transactional outbox (handoff §4.5; platform.md §5.6; sales.md §5, §6): a critical event — order
 * placed, cancelled, delivered, a return completed, a payment's outcome — is **written in the same
 * transaction as the change it tells of**, so it is never lost and never told of a change that rolled
 * back. `OutboxRelay` dispatches it afterwards, at least once; each consumer acts on it once
 * (`ProcessedEvents`).
 *
 * An event goes in as it is: its class, and its public properties — ids only, and the moment, kept as
 * an ISO 8601 string to the microsecond. Its `eventId` (a UUID) is the row's key, so the same event
 * written twice is one.
 */
final readonly class Outbox
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function record(object $event): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('An event goes into the outbox inside the transaction of its change.');
        }

        $payload = [];

        foreach ((new ReflectionClass($event))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($event);
            $payload[$property->getName()] = $value instanceof DateTimeInterface ? $value->format('Y-m-d\TH:i:s.uP') : $value;
        }

        $eventId = $payload['eventId'] ?? null;

        // Checked here, not by the database: a refused insert would end the caller's whole transaction.
        if (! is_string($eventId) || ! Str::isUuid($eventId)) {
            throw new LogicException($event::class.' has no eventId that is a UUID: an event goes into the outbox with its own id.');
        }

        $this->db->table('public.outbox_messages')->insertOrIgnore([
            'event_id' => $eventId,
            'type' => $event::class,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'available_at' => now(),
            'attempts' => 0,
        ]);
    }
}
