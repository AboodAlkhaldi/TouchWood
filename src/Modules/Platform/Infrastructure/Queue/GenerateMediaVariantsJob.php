<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Platform\Application\Command\GenerateMediaVariants\GenerateMediaVariants;
use Modules\Platform\Application\Command\GenerateMediaVariants\GenerateMediaVariantsHandler;
use Throwable;

/**
 * Three attempts, then the media is marked FAILED and staff can retry it (Platform spec §4.1).
 */
final class GenerateMediaVariantsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds before the second and third attempt */
    public array $backoff = [10, 60];

    /**
     * Below the queue's retry_after (90 seconds in config/queue.php), so a slow run is stopped
     * before another worker could pick up the same attempt.
     */
    public int $timeout = 80;

    public function __construct(
        public readonly string $mediaId,
    ) {}

    public function handle(GenerateMediaVariantsHandler $handler): void
    {
        $handler->handle(new GenerateMediaVariants($this->mediaId));
    }

    public function failed(?Throwable $error): void
    {
        app(GenerateMediaVariantsHandler::class)->fail(new GenerateMediaVariants($this->mediaId));
    }
}
