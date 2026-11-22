<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Shared\Application\Actor;
use Shared\Application\ActorContext;

/**
 * Access's ActorContext (spec §2.5), replacing Platform's interim one. A web request acts as whoever
 * its session names, else as a guest — never as the system. Outside a web request (the console,
 * a queue worker) it is the system; Platform's wrapper makes a queued job the system acting for
 * whoever queued it.
 */
final readonly class RequestActorContext implements ActorContext
{
    /**
     * @param  bool  $webServer  a web server process, where everything serves a request
     */
    public function __construct(
        private RequestActor $request,
        private bool $webServer,
    ) {}

    public function current(): Actor
    {
        return $this->request->actor() ?? ($this->webServer ? $this->request->guest() : Actor::system());
    }
}
