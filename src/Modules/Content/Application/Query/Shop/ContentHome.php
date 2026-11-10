<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Shop;

use DateTimeImmutable;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Model\BannerPlacement;
use Modules\Content\Domain\Model\HomeSectionType;
use Modules\Content\Domain\Service\HomeSectionSettings;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * **The home page's Content** (content.md §1.4), for a store and a language: the shown sections in their order, with
 * their words in that language, the logos, the home banners, the newest project and the two newest posts - **cached
 * per store and language** (`home:{store}:{locale}`), everything that isn't the shopper's. Best sellers, the category
 * tiles and the Points figures depend on the shopper or the settings and are read per request by the page (plan
 * PR 10); this gives their headings and counts.
 *
 * **Each banner carries its start and end and this filters by the clock**, then keeps the first in order a placement
 * shows at once (§1.6: one desktop hero, three phone banners). **A section with nothing to show draws nothing**: the
 * projects row while no project is published (§1.13), a banner row with no live banner, logos whose files aren't
 * ready. An image whose sizes aren't ready is left out.
 */
final readonly class ContentHome
{
    public function __construct(
        private ContentCache $cache,
        private ShopReads $reads,
    ) {}

    /**
     * @return array{sections: list<array{type: string, words: array<array-key, mixed>, logos?: list<array<string, mixed>>, project?: array<string, mixed>, guides?: list<array<string, mixed>>}>, heroBanner: array<string, mixed>|null, phoneBanners: list<array<string, mixed>>}
     */
    public function for(StoreDto $store, string $locale, ?DateTimeImmutable $now = null): array
    {
        $now ??= Moments::now();
        $kept = $this->cache->remember("home:{$store->id}:{$locale}", fn (): array => $this->reads->home($store->id));

        $live = array_values(array_filter($kept['banners'], static fn (array $banner): bool => ContentFrame::liveAt($banner, $now)));
        $banners = [];

        foreach ([BannerPlacement::HomeHero, BannerPlacement::HomePhone] as $placement) {
            $mine = array_map(static fn (array $banner): array => self::banner($banner, $placement, $locale), array_values(array_filter($live, static fn (array $banner): bool => $banner['placement'] === $placement->value)));
            // One whose image failed after it was scheduled draws as if it were not there.
            $banners[$placement->value] = array_slice(array_values(array_filter($mine, static fn (array $banner): bool => $banner['image'] !== [])), 0, $placement->liveAtOnce());
        }

        $sections = [];

        foreach ($kept['sections'] as $section) {
            $type = HomeSectionType::from((string) $section['type']);
            $settings = is_array($section['settings']) ? $section['settings'] : [];
            $drawn = ['type' => $type->value, 'words' => HomeSectionSettings::in($settings, $locale)];

            if ($type === HomeSectionType::Projects) {
                if ($kept['projects'] === []) {
                    continue;
                }

                $drawn['project'] = self::document($kept['projects'][0], $locale);
                $drawn['guides'] = array_map(static fn (array $post): array => self::document($post, $locale), $kept['posts']);
            }

            if ($type === HomeSectionType::ClientLogos) {
                $logos = array_values(array_filter(is_array($section['logos']) ? $section['logos'] : [], static fn (array $logo): bool => $logo['image'] !== []));

                if ($logos === []) {
                    continue;
                }

                $drawn['logos'] = array_map(static fn (array $logo): array => ['name' => $logo["name_{$locale}"], 'link' => $logo['link'], 'image' => $logo['image']], $logos);
            }

            if ($type === HomeSectionType::Hero && $banners[BannerPlacement::HomeHero->value] === [] && $banners[BannerPlacement::HomePhone->value] === [] && ($drawn['words']['title'] ?? null) === null) {
                continue;
            }

            $sections[] = $drawn;
        }

        return [
            'sections' => $sections,
            'heroBanner' => $banners[BannerPlacement::HomeHero->value][0] ?? null,
            'phoneBanners' => $banners[BannerPlacement::HomePhone->value],
        ];
    }

    /**
     * @param  array<string, mixed>  $banner
     * @return array<string, mixed>
     */
    private static function banner(array $banner, BannerPlacement $placement, string $locale): array
    {
        $images = is_array($banner['images']) ? $banner['images'] : [];
        $kind = $placement === BannerPlacement::HomeHero ? 'DESKTOP' : 'PHONE';

        return [
            'id' => $banner['id'],
            'link' => $banner['link'],
            'alt' => $banner["alt_{$locale}"],
            'image' => $images["{$kind}:{$locale}"] ?? $images["{$kind}:any"] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function document(array $document, string $locale): array
    {
        return [
            'title' => $document["title_{$locale}"],
            'slug' => $document["slug_{$locale}"],
            'line' => $document["line_{$locale}"],
            'image' => $document['image'],
        ];
    }
}
