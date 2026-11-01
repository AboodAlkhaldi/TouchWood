<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Queue;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Payments\Application\Attempt\AttemptChecks;

/**
 * One of an attempt's booked checks (payments.md §1.1 rule 9). It carries the attempt's id and nothing else. At most
 * 60 attempts a minute across the system (§1.8), counting only checks really due: over it, it comes back shortly.
 */
final class CheckAttemptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int CHECKS_A_MINUTE = 60;

    private const int BUSY_SECONDS = 30;

    /** Failures, not the waits for the limit; the hourly look mends anything lost. */
    public int $maxExceptions = 3;

    /**
     * An ask can make two or three calls of up to three tries of 30 seconds, so a hanging gateway never kills a run before
     * the hold limit can release its try. The queue connection's `retry_after` must be longer (HANDOFF's deploy notes).
     */
    public int $timeout = 300;

    /** @var list<int> seconds before each retry: a short database blip does not use them all up at once */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $attemptId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        // Beyond the longest delay a check is booked with, an hour, so a late pick-up still runs.
        return Carbon::now()->addHours(3);
    }

    public function handle(AttemptChecks $checks): void
    {
        if (! $checks->isDue($this->attemptId)) {
            return;
        }

        if (RateLimiter::tooManyAttempts('payments-checks', self::CHECKS_A_MINUTE)) {
            $this->release(RateLimiter::availableIn('payments-checks'));

            return;
        }

        RateLimiter::hit('payments-checks', 60);

        if (! $checks->due($this->attemptId)) {
            $this->release(self::BUSY_SECONDS);
        }
    }
}
