<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Queue;

use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\Moments;
use Modules\Content\Infrastructure\Eloquent\Rows;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * **A queued job runs once, whatever the queue hands out** (PR 4 review), as the middleware of Content's long jobs.
 *
 * The database queue gives a job still running to a second worker once its connection's `retry_after` has passed.
 * The sitemaps' build (600 s) and a page of Check Addresses (1200 s) run on the long connection, whose 3700 s is above
 * both (owner, 2026-11-10), and this stays for a copy handed out all the same. That copy carries the job's uuid. So
 * a job holds a session lock named for its uuid while it runs, and writes its uuid to `content.job_runs` when it
 * finishes: **a copy that finds the lock held (the job is running) or the row there (it finished) deletes itself**,
 * never running the work again - or, for the build, releasing itself until the running one ends and then building
 * again, which would make every long build start the next, forever.
 *
 * A job that failed wrote no row, so the queue's retry of it (the same uuid) runs; a job that released itself (a
 * build waiting for another) wrote none either, so its release runs. Rows older than `KEEP_DAYS` go as each job ends.
 */
final readonly class RunOnce
{
    public const int KEEP_DAYS = 2;

    public function __construct(
        private ConnectionInterface $db,
        private LoggerInterface $log,
    ) {}

    public function handle(BuildSitemapsJob|CheckRedirectsJob $job, Closure $next): void
    {
        $queued = $job->job;
        $uuid = $queued instanceof Job ? $queued->uuid() : null;

        // Run by hand, not by the queue: nothing can be handed out twice.
        if ($uuid === null || $uuid === '') {
            $next($job);

            return;
        }

        $key = 'content:job:'.$uuid;
        $got = $this->db->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS got', [$key], false);

        if (! $got instanceof stdClass || ! (bool) $got->got) {
            // A copy of the job running now.
            $job->delete();

            return;
        }

        try {
            if ($this->db->table('content.job_runs')->where('uuid', $uuid)->exists()) {
                // A copy of the job that finished.
                $job->delete();

                return;
            }

            $next($job);

            if (! $queued->isReleased()) {
                $this->db->table('content.job_runs')->insertOrIgnore(['uuid' => $uuid, 'finished_at' => Rows::at(Moments::now())]);
                $this->db->table('content.job_runs')->where('finished_at', '<', Rows::at(Moments::now()->modify('-'.self::KEEP_DAYS.' days')))->delete();
            }
        } finally {
            $released = $this->db->selectOne('SELECT pg_advisory_unlock(hashtext(?)) AS released', [$key], false);

            if (! $released instanceof stdClass || ! (bool) $released->released) {
                $this->log->warning('Content: a job\'s run lock was not held when it ended.', ['job' => $job::class, 'uuid' => $uuid]);
            }
        }
    }
}
