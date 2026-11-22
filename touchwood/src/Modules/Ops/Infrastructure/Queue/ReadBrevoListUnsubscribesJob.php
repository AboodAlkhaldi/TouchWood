<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Infrastructure\External\BrevoUnsubscribeRead;

/**
 * One Brevo list's part of the nightly read (ops.md §9.6 #1; BrevoUnsubscribeRead). Retried as the list jobs are
 * (ProviderFailureRules): about a day of waits when Brevo is away or refuses the key, at most MOST_LIST_ATTEMPTS
 * runs, then the failed jobs. Unique per list, so the next night queues none while one still waits. It carries the
 * list's Brevo id and the night's moment, never the key or an address.
 */
final class ReadBrevoListUnsubscribesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** No limit on tries, which the waits of ProviderFailureRules would use up: failures are what count. */
    public int $tries = 0;

    public int $maxExceptions = ProviderFailureRules::LIST_JOB_FAILURES;

    /** @var list<int> seconds before each retry */
    public array $backoff = ProviderFailureRules::LIST_JOB_BACKOFF;

    /**
     * At most 15 pages, each call waiting 13 s at most (195 s), and the unsubscribes taken: 280 s, 30 s or more under
     * the normal connection's `retry_after` of 330 s (docs/HANDOFF.md §15.4; Platform's QueueConnectionsTest), so it
     * needs no LongQueue. Past it, the failed jobs at once (`$failOnTimeout`), and the next night reads the list again.
     */
    public int $timeout = 280;

    public bool $failOnTimeout = true;

    /** Longer than the list jobs' waits, about two days at most; the lock goes as soon as the job ends or fails. */
    public int $uniqueFor = 172_800;

    public function __construct(
        public int $listId,
        public string $runAt,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->listId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ProviderFailureRules];
    }

    public function handle(Config $config, BrevoUnsubscribeRead $read): void
    {
        // Switched off while it waited: nothing more is read.
        if (! ReadBrevoUnsubscribesJob::switchedOn($config)) {
            return;
        }

        $read->read($this->listId, CarbonImmutable::parse($this->runAt)->toDateTimeImmutable());
    }
}
