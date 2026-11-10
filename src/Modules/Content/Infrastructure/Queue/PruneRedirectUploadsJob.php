<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Content\Application\Command\PruneRedirectUploads\PruneRedirectUploads;
use Modules\Content\Application\Command\PruneRedirectUploads\PruneRedirectUploadsHandler;

/** Each hour: checked redirect sheets older than 24 hours removed (content.md §9.3 #31). */
final class PruneRedirectUploadsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try: the next hour is the retry. */
    public int $tries = 1;

    public function handle(PruneRedirectUploadsHandler $handler): void
    {
        $handler->handle(new PruneRedirectUploads);
    }
}
