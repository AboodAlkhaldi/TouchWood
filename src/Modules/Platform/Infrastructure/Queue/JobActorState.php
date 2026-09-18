<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Queue;

use Shared\Application\Actor;

/**
 * The actor of the queued job running right now, if any. Process-wide, because a queue worker
 * runs jobs one after another — and with the "sync" queue a job runs inside a web request, so the
 * request's own actor must come back when the job ends. A stack, because a sync job can dispatch
 * another sync job.
 *
 * Each entry is keyed by its job, so leaving removes exactly that job's entry: a job whose entry
 * was never added (a listener failed before it) or is left twice cannot pop another job's actor.
 */
final class JobActorState
{
    /** @var list<array{job: int, actor: Actor}> */
    private array $stack = [];

    public function enter(int $job, Actor $actor): void
    {
        $this->stack[] = ['job' => $job, 'actor' => $actor];
    }

    public function leave(int $job): void
    {
        $this->stack = array_values(array_filter($this->stack, fn (array $entry): bool => $entry['job'] !== $job));
    }

    public function current(): ?Actor
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)]['actor'];
    }
}
