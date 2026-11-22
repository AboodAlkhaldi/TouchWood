<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Queue;

use Shared\Application\Actor;
use Shared\Application\ActorContext;

/**
 * Wraps whichever ActorContext is registered — the interim one now, Access's later — so that a
 * queued job always acts as the system on behalf of whoever queued it (owner's decision,
 * 2026-09-18). The person's permission was checked when they started the action; the job then
 * does system work, and the audit log records who asked for it.
 *
 * The check happens on every call rather than once, because services such as the authorizer and
 * the audit log keep a reference to this object for the whole request.
 */
final readonly class JobAwareActorContext implements ActorContext
{
    public function __construct(
        private ActorContext $inner,
        private JobActorState $jobs,
    ) {}

    public function current(): Actor
    {
        return $this->jobs->current() ?? $this->inner->current();
    }
}
