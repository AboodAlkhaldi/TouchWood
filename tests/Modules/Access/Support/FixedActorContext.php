<?php

declare(strict_types=1);

namespace Tests\Modules\Access\Support;

use Shared\Application\Actor;
use Shared\Application\ActorContext;

/**
 * Stands in for sign-in, which arrives in step 3: whoever the test says is acting.
 */
final readonly class FixedActorContext implements ActorContext
{
    public function __construct(
        private Actor $actor,
    ) {}

    public function current(): Actor
    {
        return $this->actor;
    }
}
