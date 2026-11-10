<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Queue;

use Closure;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * **One sitemap build at a time** (PR 4 review), as `BuildSitemapsJob`'s middleware: the build holds a PostgreSQL
 * session lock for as long as it runs, and a build that finds it held goes back to the queue for `WAIT` seconds,
 * then builds after, with what changed meanwhile.
 *
 * A session lock rather than the cache's: it ignores transactions, so it never aborts one (the database cache's lock
 * inserts and catches the duplicate, which fails a PostgreSQL transaction), and it goes with its connection, so a
 * worker that dies holding it stops nothing.
 */
final readonly class OneSitemapBuild
{
    /** The name every build's lock is taken by. */
    public const string KEY = 'content:sitemaps-build';

    /** Seconds a build waits, back in the queue, while another runs. */
    public const int WAIT = 60;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function handle(BuildSitemapsJob $job, Closure $next): void
    {
        $got = $this->db->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS got', [self::KEY]);

        if (! $got instanceof stdClass || ! (bool) $got->got) {
            $job->release(self::WAIT);

            return;
        }

        try {
            $next($job);
        } finally {
            $this->db->select('SELECT pg_advisory_unlock(hashtext(?))', [self::KEY]);
        }
    }
}
