<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\QueueWantedSitemaps;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentJobs;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Repository\WaitingJobs;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Queues the sitemaps' build a change wants (content.md §6): a store switched on or off at once; Catalog's changes
 * **at most once a day beyond the nightly** (§9.3 #35), the day read in UTC (the build's pick) - a change past that
 * waits for the night. Never while one waits. The system's, reserved (`content.sitemap.build`).
 */
final readonly class QueueWantedSitemapsHandler
{
    public const string PERMISSION = ContentPermissions::SITEMAP_BUILD;

    public function __construct(
        private Authorizer $authorizer,
        private WaitingJobs $waiting,
        private ContentJobs $jobs,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return bool whether a build was queued
     *
     * @throws Unauthorized
     */
    public function handle(QueueWantedSitemaps $command): bool
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        return $this->db->transaction(function (): bool {
            $now = Moments::now();

            if (! $this->waiting->takeWanted(WaitingJobs::BUILD_SITEMAPS, $now, $now->format('Y-m-d'))) {
                return false;
            }

            $this->jobs->buildSitemaps();

            return true;
        }, 3);
    }
}
