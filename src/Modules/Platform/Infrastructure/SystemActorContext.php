<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure;

use Shared\Application\Actor;
use Shared\Application\ActorContext;

/**
 * Interim binding until Access exists (build stage 2): with no login yet, every actor is the
 * system — console commands, seeders and jobs. Access replaces this binding.
 */
final class SystemActorContext implements ActorContext
{
    public function current(): Actor
    {
        return Actor::system();
    }
}
