<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\EndBanner;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Banner;
use Modules\Content\Domain\Model\ScheduleState;
use Modules\Content\Domain\Repository\Banners;
use Modules\Content\Domain\ValueObject\Schedule;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Ends a live banner now (content.md §4.1): its end becomes this moment. Only a live one: a scheduled one goes back to
 * draft instead, an ended one is history.
 */
final readonly class EndBannerHandler
{
    public const string PERMISSION = ContentPermissions::BANNER_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Banners $banners,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(EndBanner $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->banners->find($command->bannerId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);
        $staffId = $this->access->staffId();

        $this->db->transaction(function () use ($command, $store, $staffId): void {
            $was = $this->banners->lock($command->bannerId) ?? throw new ContentNotFound;
            $now = Moments::now();

            if ($was->schedule->state($now) !== ScheduleState::Live) {
                throw new InvalidContentInput('state', InvalidContentInput::STATE);
            }

            [$starts, $ends] = Moments::endNow($was->schedule->startsAt, $now);
            $banner = new Banner($was->id, $was->storeId, $was->placement, $was->categoryId, $was->name, $was->link, $was->hasWords, $was->altAr, $was->altEn, new Schedule(true, $starts, $ends), $was->position, $was->images, $was->version + 1, $staffId, $now);
            $this->banners->update($banner);
            $this->platform->recordAudit(ContentAudit::entry('content.banner.ended', 'content.banner', $was->id, $store->id, ContentAudit::diff($was->fields(), $banner->fields())));
            $this->cache->invalidate();
        }, 3);
    }
}
