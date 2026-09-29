<?php

declare(strict_types=1);

namespace Modules\Platform\Application\FailedJobs;

/**
 * The queue's failed work (platform.md §3, §5.6). Nothing here deletes a job on its own: one stays
 * until an admin retries or deletes it.
 */
interface FailedJobs
{
    /**
     * @return list<FailedJob> oldest first
     */
    public function all(): array;

    public function find(string $id): ?FailedJob;

    /**
     * The job, its row locked until the transaction ends: two admins retrying or deleting the same
     * one take turns, and the second finds it gone.
     */
    public function lock(string $id): ?FailedJob;

    /**
     * Back on its own connection and queue, its attempts counted afresh — as Laravel's `queue:retry`
     * does. Call inside the transaction that then forgets it: the queue is in the same database, so
     * the job is never both queued and listed, nor neither.
     */
    public function requeue(FailedJob $job): void;

    public function forget(string $id): void;

    public function count(): int;
}
