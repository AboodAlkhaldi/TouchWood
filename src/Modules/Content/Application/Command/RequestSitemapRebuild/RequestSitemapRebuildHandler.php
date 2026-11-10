<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\RequestSitemapRebuild;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentJobs;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Repository\WaitingJobs;
use Shared\Application\Unauthorized;

/**
 * Queues the sitemaps' build from W10's Rebuild Sitemap (content.md §1.14): under the SEO permission of the store
 * whose screen it was pressed in. The build makes every on store's at once (one index), so **one waits at a time**:
 * while one does, a press queues nothing (W10: "disabled while one waits").
 */
final readonly class RequestSitemapRebuildHandler
{
    public const string PERMISSION = ContentPermissions::SEO_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private WaitingJobs $waiting,
        private ContentJobs $jobs,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return bool whether one was queued now (false: one waits already)
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(RequestSitemapRebuild $command): bool
    {
        $this->access->authorize(self::PERMISSION, $command->storeId);

        return $this->db->transaction(function (): bool {
            if (! $this->waiting->ask(WaitingJobs::BUILD_SITEMAPS, Moments::now())) {
                return false;
            }

            $this->jobs->buildSitemaps();

            return true;
        });
    }
}
