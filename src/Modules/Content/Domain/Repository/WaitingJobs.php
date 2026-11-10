<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use DateTimeImmutable;

/**
 * Whether one of Content's jobs is asked for (`content.waiting_jobs`): queued and waiting - the button is disabled
 * while it does, and a second ask queues nothing - or, for the sitemaps, wanted by a change since the last build.
 */
interface WaitingJobs
{
    public const string BUILD_SITEMAPS = 'BUILD_SITEMAPS';

    public const string CHECK_REDIRECTS = 'CHECK_REDIRECTS';

    /**
     * A job queued longer ago than this is taken as lost and no longer waits (the build's pick, PR 4 review): a
     * sitemap build takes minutes and Check Addresses a page of lines a job, so six hours is far past either.
     */
    public const int STALE_HOURS = 6;

    /**
     * Marks the job as queued, unless one waits already. Under the row's lock.
     *
     * @return bool whether it was marked, so a job is to be queued
     */
    public function ask(string $job, DateTimeImmutable $at): bool;

    /**
     * A change wants the job run again: kept until the next tick takes it up. In one write, with no lock to wait on.
     *
     * @param  bool  $now  true for a change taken up at the next tick whatever the day (a store switched on or off);
     *                     false for one taken up only if no such build ran that day (Catalog's changes)
     */
    public function want(string $job, DateTimeImmutable $at, bool $now): void;

    /**
     * Takes a wanted run up, as queued: when nothing waits, something wants it, and it may run today - a change
     * wanting it now, or no extra run on `$today` yet (which it then becomes). Under the row's lock.
     *
     * @return bool whether it was taken, so a job is to be queued
     */
    public function takeWanted(string $job, DateTimeImmutable $at, string $today): bool;

    /** The job has started: what was asked or wanted until now is under way, and a change from now on asks again. */
    public function started(string $job): void;

    /** When the waiting one was queued, or null when none waits. */
    public function waitingSince(string $job): ?DateTimeImmutable;
}
