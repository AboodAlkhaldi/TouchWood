<?php

declare(strict_types=1);

namespace Modules\Platform\Application\FailedJobs;

use DateTimeImmutable;

/**
 * The queue's failed work (platform.md §3, §5.6). Nothing here deletes a job on its own: one stays
 * until an admin retries or deletes it.
 */
interface FailedJobs
{
    /**
     * One page, oldest first, after the given job — the time it failed and its id, the last row of
     * the page before — or from the start.
     *
     * @return list<FailedJobSummary>
     */
    public function page(?DateTimeImmutable $afterFailedAt, ?string $afterId, int $limit): array;

    /** Null as well for an id that is not a uuid: no failed job could have it. */
    public function find(string $id): ?FailedJob;

    /**
     * The job, its row locked until the transaction ends: two admins retrying or deleting the same
     * one take turns, and the second finds it gone.
     */
    public function lock(string $id): ?FailedJob;

    /**
     * Whether a job that failed on this connection can be put back safely: only the database queue
     * in this same database, where the retry and the list change in one transaction. Another
     * queue's push could be lost (sync) or survive a rollback (redis, sqs).
     */
    public function retryable(string $connection): bool;

    /**
     * Back on its own connection and queue, its attempts counted afresh — as Laravel's `queue:retry`
     * does for the database queue. Call only for a retryable job, inside the transaction that then
     * forgets it: the job is never both queued and listed, nor neither.
     */
    public function requeue(FailedJob $job): void;

    public function forget(string $id): void;

    public function count(): int;
}
