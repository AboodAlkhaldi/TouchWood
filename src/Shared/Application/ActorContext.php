<?php

namespace Shared\Application;

/**
 * Who is acting right now. Implemented by Access; console commands and jobs act as the system.
 */
interface ActorContext
{
    public function current(): Actor;
}
