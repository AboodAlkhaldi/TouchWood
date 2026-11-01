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
 * 60 attempts a minute across the system (§1.8): over it, it comes back shortly.
 */
final class CheckAttemptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int CHECKS_A_MINUTE = 60;

    /** Failures, not the waits for the limit; the hourly look mends anything lost. */
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $attemptId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::now()->addHour();
    }

    public function handle(AttemptChecks $checks): void
    {
        if (RateLimiter::tooManyAttempts('payments-checks', self::CHECKS_A_MINUTE)) {
            $this->release(RateLimiter::availableIn('payments-checks'));

            return;
        }

        RateLimiter::hit('payments-checks', 60);
        $checks->due($this->attemptId);
    }
}
