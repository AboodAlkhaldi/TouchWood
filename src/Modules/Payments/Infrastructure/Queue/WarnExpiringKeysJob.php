<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Payments\Application\Gateway\KeyExpiryWarnings;

/**
 * The gateway keys' expiry warnings (payments.md §1.2), every hour: each store's day starts at its own
 * midnight, and a warning is sent once whenever the hour finds it due.
 */
final class WarnExpiringKeysJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); the next hour mends a miss. */
    public int $tries = 3;

    public function handle(KeyExpiryWarnings $warnings): void
    {
        $warnings->send();
    }
}
