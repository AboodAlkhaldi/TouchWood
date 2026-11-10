<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteBanner;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\NotDeletable;
use Modules\Content\Domain\Repository\Banners;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Deletes a draft banner (content.md §1.1 rule 11): a scheduled, live or ended one is refused - an ended one is
 * history and stays (`NotDeletable`). Its image rows go with it; the files stay in the library. Audited by value.
 */
final readonly class DeleteBannerHandler
{
    public const string PERMISSION = ContentPermissions::BANNER_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Banners $banners,
        private ContentLocks $locks,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(DeleteBanner $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->banners->find($command->bannerId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);

        $this->db->transaction(function () use ($command, $store): void {
            $this->locks->lock($store->id, ContentLocks::BANNERS);
            $was = $this->banners->lock($command->bannerId) ?? throw new ContentNotFound;

            if ($was->schedule->isScheduled) {
                throw new NotDeletable;
            }

            $this->banners->delete($was->id);
            $rest = array_values(array_filter($this->banners->inPlacement($store->id, $was->placement), static fn ($banner): bool => $banner->id !== $was->id));
            $this->banners->reorder(array_map(static fn ($banner): string => $banner->id, $rest));
            $this->platform->recordAudit(ContentAudit::entry('content.banner.deleted', 'content.banner', $was->id, $store->id, ContentAudit::diff($was->fields(), [])));
            $this->cache->invalidate();
        });
    }
}
