<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Modules\Ops\Application\Messages\FailureKind;
use Modules\Ops\Application\Messages\ProviderFailed;

/**
 * How a job meets a provider's failure (ops.md §7). The jobs have `$tries = 0` and `$maxExceptions`: a wait here is no
 * exception and spends nothing, and no time limit is set, which a retry from the failed jobs screen would find passed
 * (Platform pushes the payload back as it is).
 *
 * - **One address refused:** the failed jobs at once, its row marked as a last attempt would. Nobody else is held up.
 * - **Waiting for a person** (a key, the credit, the IP, the sender): back after a long wait, for up to a day, so topping
 *   up or fixing the key mends all of it without anyone retrying thousands of jobs. Each job makes one call a step,
 *   so a broadcast does not spin. Then the failed jobs, with its reason.
 * - **Too many calls:** back after the wait Brevo named. A limit on the attempts keeps it from going round for ever.
 * - **Anything else** goes on to the queue, by the job's backoff, until `$maxExceptions`.
 *
 * Counted by the job's attempts: a job that already met another failure has fewer of the long waits left.
 */
final class ProviderFailureRules
{
    /** Seconds of each long wait, about 24 hours in all: 5, 15 and 30 minutes, 1, 2, 4, 8 and 8 hours. */
    public const array PERSON_WAITS = [300, 900, 1800, 3600, 7200, 14400, 28800, 28800];

    public const int MOST_RATE_LIMITED = 300;

    /**
     * @param  SendEventEmailJob|SendBroadcastEmailJob|SyncSubscriberJob|RemoveNewsletterContactJob|DeliverDailyReportJob  $job
     * @param  callable(object): mixed  $next
     *
     * @throws ProviderFailed
     */
    public function handle(object $job, callable $next): mixed
    {
        try {
            return $next($job);
        } catch (ProviderFailed $failed) {
            if ($failed->kind === FailureKind::RateLimited && $failed->retryAfter !== null && $job->attempts() < self::MOST_RATE_LIMITED) {
                $job->release($failed->retryAfter);

                return null;
            }

            if ($failed->kind === FailureKind::WaitsForPerson && isset(self::PERSON_WAITS[$job->attempts() - 1])) {
                // No exception reaches the worker, so this is the only line the log gets.
                report($failed);
                $job->release(self::PERSON_WAITS[$job->attempts() - 1]);

                return null;
            }

            if (in_array($failed->kind, [FailureKind::OneAddress, FailureKind::WaitsForPerson, FailureKind::RateLimited], true)) {
                $job->fail($failed);
            }

            throw $failed;
        }
    }
}
