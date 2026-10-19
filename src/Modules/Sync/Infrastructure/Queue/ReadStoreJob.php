<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\ValueObject\ReadFailure;
use Throwable;

/**
 * One read of a store (sync.md §1.3), queued once the read is asked for. A connection that fails is
 * tried again after 1, 5 and 15 minutes (§1.7); after the last, the read fails, the store is marked
 * failing and reported. Never one job per size: a read is batched within (handoff §12.2).
 */
final class ReadStoreJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** The first try and three more (platform.md §3: every queued job states its own). */
    public int $tries = 4;

    /** @var list<int> 1, 5 and 15 minutes (sync.md §1.7) */
    public array $backoff = [60, 300, 900];

    /**
     * A big store's full read may take minutes. Shorter than the 30 minutes after which a read is taken
     * as dead (`RunReadHandler`); its queue connection's `retry_after` must be longer (HANDOFF's deploy
     * notes, as for the product import).
     */
    public int $timeout = 1200;

    public function __construct(
        public readonly string $readId,
    ) {}

    public function handle(RunReadHandler $handler): void
    {
        $wait = $handler->handle(new RunRead($this->readId));

        // Its read runs elsewhere and is not yet taken as dead: back in the queue until it is.
        if ($wait !== null) {
            $this->release($wait);
        }
    }

    public function failed(?Throwable $error): void
    {
        // The provider's reason is shown to staff as it came; anything else is Sync's own code (Q31),
        // its detail left on the failed jobs screen.
        app(RunReadHandler::class)->failed($this->readId, $error instanceof ConnectionFailed ? $error->getMessage() : ReadFailure::JobFailed->value);
    }
}
