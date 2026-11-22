<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sales\Application\Command\DeleteExpiredQuotes\DeleteExpiredQuotes;
use Modules\Sales\Application\Command\DeleteExpiredQuotes\DeleteExpiredQuotesHandler;

/** Quotes past their expiry, deleted each night as the system (sales.md §5). */
final class DeleteExpiredQuotesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try (platform.md §3): tomorrow's run takes whatever this one left. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(DeleteExpiredQuotesHandler $handler): void
    {
        $handler->handle(new DeleteExpiredQuotes);
    }
}
