<?php

declare(strict_types=1);

namespace Shared\Application;

/**
 * Who is acting right now. Implemented by Access; console commands act as the system.
 *
 * Inside a queued job the answer is always the system, carrying whoever's action queued the job
 * (Platform wraps every implementation to do this). So register an implementation with bind() or
 * scoped(), never instance(): the container only wraps bindings.
 */
interface ActorContext
{
    public function current(): Actor;
}
