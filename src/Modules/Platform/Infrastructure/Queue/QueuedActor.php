<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Queue;

use Shared\Application\Actor;
use Shared\Application\ActorType;

/**
 * Carries "who asked for this" from the moment a job is queued to the moment it runs.
 *
 * When a job is queued, the current actor — or, inside another job, that job's requester — is
 * written into the job's payload. When the job runs, it acts as the system on that requester's
 * behalf (see JobAwareActorContext).
 */
final class QueuedActor
{
    private const string PAYLOAD_KEY = 'requestedBy';

    /**
     * @return array<string, array{type: string, id: string|null}> merged into the job's payload
     */
    public static function payloadFor(Actor $current): array
    {
        $requester = $current->type === ActorType::System ? $current->requestedBy : $current;

        return $requester === null ? [] : [self::PAYLOAD_KEY => ['type' => $requester->type->value, 'id' => $requester->id]];
    }

    /**
     * @param  array<array-key, mixed>  $payload  the job's decoded payload
     */
    public static function actorFor(array $payload): Actor
    {
        $requester = $payload[self::PAYLOAD_KEY] ?? null;

        if (! is_array($requester) || ! is_string($requester['type'] ?? null)) {
            return Actor::system();
        }

        $id = $requester['id'] ?? null;

        return Actor::system(Actor::of(ActorType::from($requester['type']), is_string($id) ? $id : null));
    }
}
