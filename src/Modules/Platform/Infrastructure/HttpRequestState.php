<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure;

/**
 * Whether an HTTP request is being handled right now, set by TrackHttpRequest around it.
 *
 * Laravel's runningInConsole() cannot answer this: tests run in the console even while they send
 * HTTP requests, and a queued job can run inside a request. Only the middleware knows.
 */
final class HttpRequestState
{
    private int $depth = 0;

    public function enter(): void
    {
        $this->depth++;
    }

    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    public function isHandling(): bool
    {
        return $this->depth > 0;
    }
}
