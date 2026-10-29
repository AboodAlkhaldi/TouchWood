<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Newsletter\NewsletterSync;

/** Brings the newsletter provider in line with one subscriber's row (ops.md §1.9). Run again, it changes nothing. */
final class SyncSubscriberJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Five attempts over about 36 minutes: Brevo away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public string $subscriberId,
    ) {}

    public function handle(NewsletterSync $sync): void
    {
        $sync->sync($this->subscriberId);
    }
}
