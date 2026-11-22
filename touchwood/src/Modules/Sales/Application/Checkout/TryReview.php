<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Checkout;

use Carbon\CarbonImmutable;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Sales\Domain\Repository\CheckoutTries;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sales's hourly look at its tries (§9.5 #4), for whatever the events did not settle: a live try past its window
 * is asked about. Paid: completed; nothing taken: released; whatever cannot be settled, or is still live past the
 * hold limit, marked as needing attention, here and in Payments' list. One try's trouble never stops the rest.
 */
final readonly class TryReview
{
    public const int MOST = 200;

    /** Well inside its job's time limit. */
    public const int MOST_SECONDS = 240;

    public function __construct(
        private CheckoutTries $tries,
        private TrySettling $settling,
        private TryRelease $release,
        private PaymentsApi $payments,
        private LoggerInterface $log,
    ) {}

    public function run(): void
    {
        $now = CarbonImmutable::now();
        $started = hrtime(true);

        foreach ($this->tries->livePast($now, self::MOST) as $try) {
            // A gateway away can take a minute a try: the next hour takes the rest, the oldest first.
            if (hrtime(true) - $started > self::MOST_SECONDS * 1_000_000_000) {
                return;
            }

            try {
                // Never charged, a crash after the commit: nothing can come of it.
                if ($this->payments->abandon($try->attemptId)) {
                    $this->release->release($try->id);

                    continue;
                }

                $state = $this->settling->settle($try);
                $still = $this->tries->find($try->id)?->isLive() === true;

                if (! $still || $state->holdEndsAt === null || $state->holdEndsAt > $now) {
                    continue;
                }
            } catch (Throwable $failure) {
                $this->log->error('A checkout try could not be settled.', ['try' => $try->id, 'error' => $failure->getMessage()]);
            }

            $this->tries->markForAttention($try->id);
            $this->payments->needsAttention($try->attemptId);
        }
    }
}
