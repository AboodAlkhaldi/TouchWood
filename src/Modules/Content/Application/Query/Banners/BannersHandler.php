<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Banners;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\BannerPlacement;
use Modules\Content\Domain\Repository\Banners;
use Shared\Application\Unauthorized;

/**
 * What W3 shows (content.md §4.4): a placement's banners in order (name, where shown, state, start, end, a thumbnail)
 * with every placement's count for the tabs, and one banner's form. Under `content.banner.manage` in the store.
 */
final readonly class BannersHandler
{
    public const string PERMISSION = ContentPermissions::BANNER_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Banners $banners,
        private ScreenReads $reads,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     *
     * @throws ContentError|Unauthorized
     */
    public function list(?string $storeId, string $placement): array
    {
        $store = $this->access->authorize(self::PERMISSION, $storeId);

        return $this->reads->banners($store->id, BannerPlacement::tryFrom($placement) ?? throw new InvalidContentInput('placement', InvalidContentInput::CHOICE));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ContentError|Unauthorized
     */
    public function banner(string $bannerId): array
    {
        $this->access->holds(self::PERMISSION);
        $banner = $this->banners->find($bannerId);
        $this->access->authorize(self::PERMISSION, $banner?->storeId, item: true);

        return $banner === null ? [] : ['id' => $banner->id, ...$banner->fields(), 'images' => $banner->images, 'version' => $banner->version];
    }
}
