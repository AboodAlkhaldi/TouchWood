<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure;

/**
 * Whether the code running now serves an HTTP request.
 *
 * In a web server process the answer is always yes — including work done after the response is
 * sent (defer(), afterResponse() jobs), which runs once TrackHttpRequest has already finished. In
 * the console it is yes only inside TrackHttpRequest: tests send HTTP requests from the console,
 * and Laravel's runningInConsole() cannot tell those apart from an artisan command.
 */
final class HttpRequestState
{
    private int $depth = 0;

    public function __construct(
        private readonly bool $webServer,
    ) {}

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
        return $this->webServer || $this->depth > 0;
    }
}
