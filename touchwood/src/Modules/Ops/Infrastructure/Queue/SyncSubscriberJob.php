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

    /** No limit on tries, which the waits of ProviderFailureRules would use up: failures are what count. */
    public int $tries = 0;

    /**
     * Ten failures over about a day (owner, Content round 3 #19; ops.md §9.6 #2): a long outage mends itself, as
     * ProviderFailureRules' long waits do. The waits are the builder's pick (K5).
     */
    public int $maxExceptions = ProviderFailureRules::LIST_JOB_FAILURES;

    /** @var list<int> seconds before each retry */
    public array $backoff = ProviderFailureRules::LIST_JOB_BACKOFF;

    public function __construct(
        public string $subscriberId,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ProviderFailureRules];
    }

    public function handle(NewsletterSync $sync): void
    {
        $sync->sync($this->subscriberId);
    }
}
