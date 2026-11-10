<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\OrderBanners;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Ordering;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Banner;
use Modules\Content\Domain\Model\BannerPlacement;
use Modules\Content\Domain\Repository\Banners;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Shared\Application\Unauthorized;

/**
 * Puts a placement's banners in the order dragged (content.md §1.6: "live at once" limits are met by the order).
 * The whole order the person sees, every banner of the placement once (lesson 191); last save wins.
 */
final readonly class OrderBannersHandler
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
    public function handle(OrderBanners $command): void
    {
        $store = $this->access->authorize(self::PERMISSION, $command->storeId);
        $placement = BannerPlacement::tryFrom($command->placement) ?? throw new InvalidContentInput('placement', InvalidContentInput::CHOICE);

        $this->db->transaction(function () use ($command, $store, $placement): void {
            $this->locks->lock($store->id, ContentLocks::BANNERS);
            $present = array_map(static fn (Banner $banner): string => $banner->id, $this->banners->inPlacement($store->id, $placement));
            $ids = Ordering::of('banners', $command->bannerIds, $present);
            $this->banners->reorder($ids);
            $this->platform->recordAudit(ContentAudit::entry('content.banners.ordered', 'content.banners', $store->id, $store->id, AuditChanges::none()->changed($placement->value, implode(',', $present), implode(',', $ids))));
            $this->cache->invalidate();
        });
    }
}
