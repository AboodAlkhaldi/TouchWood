<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Queue;

use Shared\Application\Actor;

/**
 * The actor of the queued job running right now, if any. Process-wide, because a queue worker
 * runs jobs one after another — and with the "sync" queue a job runs inside a web request, so the
 * request's own actor must come back when the job ends. A stack, because a sync job can dispatch
 * another sync job.
 */
final class JobActorState
{
    /** @var list<Actor> */
    private array $stack = [];

    public function enter(Actor $actor): void
    {
        $this->stack[] = $actor;
    }

    public function leave(): void
    {
        array_pop($this->stack);
    }

    public function current(): ?Actor
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }
}
