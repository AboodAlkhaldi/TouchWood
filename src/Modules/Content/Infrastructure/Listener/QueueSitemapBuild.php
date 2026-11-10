<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Listener;

use Modules\Content\Application\Moments;
use Modules\Content\Domain\Repository\WaitingJobs;

/**
 * A change to what the sitemaps list wants them built again (content.md §6): **a store switched on or off** -
 * taken up at the next tick (it joins or leaves the index at once by the routes; its files follow) - and **Catalog's
 * changes** (a product archived or made ready, a store taking up sizes), **at most one build a day beyond the
 * nightly** (§9.3 #35: products change often). One write to Content's own row, never a build in the request that
 * made the change, and nothing of Catalog's read: `QueueWantedSitemapsJob`, every ten minutes, queues the build.
 */
final readonly class QueueSitemapBuild
{
    public function __construct(
        private WaitingJobs $waiting,
    ) {}

    /** A store switched on or off. */
    public function store(object $event): void
    {
        $this->waiting->want(WaitingJobs::BUILD_SITEMAPS, Moments::now(), now: true);
    }

    /** One of Catalog's changes to what is listed. */
    public function catalog(object $event): void
    {
        $this->waiting->want(WaitingJobs::BUILD_SITEMAPS, Moments::now(), now: false);
    }
}
