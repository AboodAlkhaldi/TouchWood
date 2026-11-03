<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Modules\Ops\Application\Messages\ProviderFailed;

/**
 * How a job meets a provider's failure (ops.md §7). One only a person can mend - a key refused, no credit, an
 * address refused - goes to the failed jobs at once, its row marked as the last attempt would: asking four more
 * times over 36 minutes changes nothing, and retrying it on the failed jobs screen sends it when it is mended.
 * One whose wait the provider named is put back for that wait, an attempt spent, the last attempt failing as
 * usual with its reason. Anything else goes on to the queue, which retries it by the job's own backoff.
 */
final class ProviderFailureRules
{
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
            if ($failed->permanent) {
                $job->fail($failed);
            } elseif ($failed->retryAfter !== null && $job->attempts() < $job->tries) {
                $job->release($failed->retryAfter);

                return null;
            }

            throw $failed;
        }
    }
}
