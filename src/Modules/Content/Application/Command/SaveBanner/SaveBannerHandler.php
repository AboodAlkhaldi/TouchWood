<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveBanner;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Contracts\CatalogApi;
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
use Modules\Content\Domain\Model\BannerPlacement;
use Modules\Content\Domain\Model\ScheduleState;
use Modules\Content\Domain\Repository\Banners;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\ValueObject\Schedule;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * Writes a banner (content.md §1.6). A new one is a draft, last in its placement's order. **Arabic is required to
 * save** - its description - and English beside it to schedule (§1.1 rule 2). A listing banner may name a category,
 * no other placement may. Images as `BannerRules` takes them. **A banner already scheduled or live stays held to
 * Schedule's rules**: English blanked, an image missing or not ready, an end already past are refused. Refused
 * over a newer version (§1.1 rule 12).
 */
final readonly class SaveBannerHandler
{
    public const string PERMISSION = ContentPermissions::BANNER_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Banners $banners,
        private BannerRules $rules,
        private EditCheck $check,
        private ContentLocks $locks,
        private CatalogApi $catalog,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @return string the banner's id
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(SaveBanner $command): string
    {
        if ($command->bannerId === null) {
            $store = $this->access->authorize(self::PERMISSION, $command->storeId);
            $placement = BannerPlacement::tryFrom($command->placement) ?? throw new InvalidContentInput('placement', InvalidContentInput::CHOICE);
        } else {
            $this->access->holds(self::PERMISSION);
            $found = $this->banners->find($command->bannerId);
            $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);
            $placement = $found->placement ?? throw new ContentNotFound;

            if ($command->placement !== $placement->value) {
                throw new InvalidContentInput('placement', InvalidContentInput::CHOICE);
            }
        }

        $staffId = $this->access->staffId();
        $fields = $this->fields($command, $placement, $store);

        return $this->db->transaction(function () use ($command, $store, $placement, $staffId, $fields): string {
            $now = Moments::now();

            if ($command->bannerId === null) {
                $this->locks->lock($store->id, ContentLocks::BANNERS);
                $id = strtolower((string) Str::ulid());
                $banner = new Banner($id, $store->id, $placement, $fields['category'], $fields['name'], $fields['link'], $command->hasWords, $fields['alt']['ar'], $fields['alt']['en'], new Schedule(false, $fields['starts'], $fields['ends']), count($this->banners->inPlacement($store->id, $placement)), $fields['images'], 1, $staffId, $now);
                $this->banners->add($banner);
                $this->platform->recordAudit(ContentAudit::entry('content.banner.created', 'content.banner', $id, $store->id, ContentAudit::diff([], $banner->fields())));
                $this->cache->invalidate();

                return $id;
            }

            $was = $this->banners->lock($command->bannerId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, $store);
            // A scheduled banner always has a start: left blank, it is Now (W3's default).
            $starts = $fields['starts'] ?? ($was->schedule->isScheduled ? $now : null);
            $banner = new Banner($was->id, $was->storeId, $placement, $fields['category'], $fields['name'], $fields['link'], $command->hasWords, $fields['alt']['ar'], $fields['alt']['en'], new Schedule($was->schedule->isScheduled, $starts, $fields['ends']), $was->position, $fields['images'], $was->version + 1, $staffId, $now);

            // Ended is history: re-dating it is a new schedule, held to the same rules as a scheduled one.
            if ($was->schedule->state($now) !== ScheduleState::Draft) {
                $this->rules->requireSchedulable($banner, $now);
            }

            $this->banners->update($banner);
            $this->platform->recordAudit(ContentAudit::entry('content.banner.changed', 'content.banner', $was->id, $store->id, ContentAudit::diff($was->fields(), $banner->fields())));
            $this->cache->invalidate();

            return $was->id;
        });
    }

    /**
     * @return array{category: string|null, name: string, link: string|null, alt: array{ar: string, en: string|null}, starts: \DateTimeImmutable|null, ends: \DateTimeImmutable|null, images: array<string, string>}
     *
     * @throws ContentError
     */
    private function fields(SaveBanner $command, BannerPlacement $placement, StoreDto $store): array
    {
        $category = $command->categoryId === null || trim($command->categoryId) === '' ? null : strtolower(trim($command->categoryId));

        if ($category !== null && ($placement !== BannerPlacement::Listing || $this->catalog->category($category) === null)) {
            throw new InvalidContentInput('category', $placement === BannerPlacement::Listing ? InvalidContentInput::CATEGORY : InvalidContentInput::CHOICE);
        }

        [$starts, $ends] = Moments::range($command->startsAt, $command->endsAt, $store);

        return [
            'category' => $category,
            'name' => Words::required('name', $command->name, 100),
            'link' => Words::link('link', $command->link),
            'alt' => Words::arabic('alt', $command->altAr, $command->altEn, 200),
            'starts' => $starts,
            'ends' => $ends,
            'images' => $this->rules->images($placement, $command->hasWords, $command->images),
        ];
    }
}
