<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sales\Application\Command\EmptyOldCarts\EmptyOldCarts;
use Modules\Sales\Application\Command\EmptyOldCarts\EmptyOldCartsHandler;

/** Carts no one touched for 90 days, emptied each night as the system (sales.md §1.1). */
final class EmptyOldCartsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try (platform.md §3): tomorrow's run takes whatever this one left. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(EmptyOldCartsHandler $handler): void
    {
        $handler->handle(new EmptyOldCarts);
    }
}
