<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ScheduleBanner;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\BannerRules;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Banner;
use Modules\Content\Domain\Repository\Banners;
use Modules\Content\Domain\ValueObject\Schedule;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Schedules a draft banner (content.md §1.6, §4.1): both descriptions (§1.1 rule 2), every image its placement takes
 * and each one ready - "Banner image is still being prepared." (§1.1 rule 10) - and an end not already past. A start
 * left empty is now. More live banners than a placement shows at once are allowed: only the first in order show.
 */
final readonly class ScheduleBannerHandler
{
    public const string PERMISSION = ContentPermissions::BANNER_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Banners $banners,
        private BannerRules $rules,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(ScheduleBanner $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->banners->find($command->bannerId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);
        $staffId = $this->access->staffId();

        $this->db->transaction(function () use ($command, $store, $staffId): void {
            $was = $this->banners->lock($command->bannerId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, $store);

            if ($was->schedule->isScheduled) {
                throw new InvalidContentInput('state', InvalidContentInput::STATE);
            }

            $now = Moments::now();
            $banner = new Banner($was->id, $was->storeId, $was->placement, $was->categoryId, $was->name, $was->link, $was->hasWords, $was->altAr, $was->altEn, new Schedule(true, $was->schedule->startsAt ?? $now, $was->schedule->endsAt), $was->position, $was->images, $was->version + 1, $staffId, $now);
            $this->rules->requireSchedulable($banner, $now);
            $this->banners->update($banner);
            $this->platform->recordAudit(ContentAudit::entry('content.banner.scheduled', 'content.banner', $was->id, $store->id, ContentAudit::diff($was->fields(), $banner->fields())));
            $this->cache->invalidate();
        }, 3);
    }
}
