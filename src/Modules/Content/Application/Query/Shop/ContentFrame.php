<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Shop;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Model\ThemePartKind;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * **What every shop page carries from Content** (content.md §1.5): the announcement bar, the footer, the pop-up, the
 * live theme's parts and what goes above a product list, for a store and a language - **one cached read**
 * (`ContentCache`, `frame:{store}:{locale}`). The cache keeps every bar, theme, smart bar, listing banner and pop-up
 * that is scheduled or live, each with its start and end, and **this filters them by the clock on each request**, so
 * each starts and ends on the second with no cache trouble.
 *
 * - The bar: shown while on and inside its dates; **a live theme's announcement part dresses it** (§1.8 part 1): its
 *   words and link over the store's, its artwork behind them. With the bar off and no theme words, nothing.
 * - The pop-up: **one at a time** - the first live one by its start. Whether a page shows it at all is the frame
 *   part's (§1.10).
 * - The theme: **one live per store at a time** (§1.8), the first live one by its start. A part's image whose sizes
 *   are not ready, or failed later, draws as if left empty (§1.8).
 */
final readonly class ContentFrame
{
    public function __construct(
        private ContentCache $cache,
        private ShopReads $reads,
    ) {}

    /**
     * @return array{bar: array{text: string, link: string|null, art?: bool}|null, footer: array<string, mixed>|null, popup: array<string, mixed>|null}
     */
    public function for(StoreDto $store, string $locale, ?DateTimeImmutable $now = null): array
    {
        return $this->in($this->kept($store, $locale), $now ?? Moments::now());
    }

    /**
     * The cached copy for a store and language, read once; `in()`, `theme()` and `listing()` filter it by the clock. A
     * caller asked several times in one request (the frame part, the theme's middleware, a list's slot) reads it once
     * and passes it on.
     *
     * @return array<string, mixed>
     */
    public function kept(StoreDto $store, string $locale): array
    {
        /** @var array<string, mixed> */
        return $this->cache->remember("frame:{$store->id}:{$locale}", fn (): array => self::inLanguage($this->reads->frame($store->id), $locale));
    }

    /**
     * The cached copy with the theme a staff member previews in place of the store's (content.md W5b): read past the
     * cache, by its id, for this store only; the copy as it was when that theme is not one of this store's.
     *
     * @param  array<string, mixed>  $kept
     * @return array{kept: array<string, mixed>, name: string|null}
     */
    public function withPreview(array $kept, StoreDto $store, string $locale, string $themeId): array
    {
        $theme = $this->reads->previewTheme($themeId, $store->id);

        if ($theme === null) {
            return ['kept' => $kept, 'name' => null];
        }

        $inLanguage = self::inLanguage(['themes' => [$theme]], $locale);
        $kept['themes'] = $inLanguage['themes'];

        return ['kept' => $kept, 'name' => $locale === 'en' && $theme['name_en'] !== null ? $theme['name_en'] : $theme['name_ar']];
    }

    /**
     * @param  array<string, mixed>  $kept
     * @return array{bar: array{text: string, link: string|null, art?: bool}|null, footer: array<string, mixed>|null, popup: array<string, mixed>|null}
     */
    public function in(array $kept, DateTimeImmutable $now): array
    {
        $popup = null;

        foreach (self::listOf($kept['popups'] ?? null) as $candidate) {
            if (self::liveAt($candidate, $now)) {
                $popup = array_diff_key($candidate, ['starts' => true, 'ends' => true]);

                break;
            }
        }

        $bar = is_array($kept['bar'] ?? null) && self::liveAt($kept['bar'], $now) ? ['text' => (string) $kept['bar']['text'], 'link' => self::text($kept['bar']['link'] ?? null)] : null;
        $dress = self::theme($kept, $now)['parts'][ThemePartKind::Announcement->value] ?? null;

        if (is_array($dress)) {
            $text = self::text($dress['text'] ?? null);

            // Its words over the store's; its artwork behind whichever words show. No words at all: nothing draws.
            if ($text !== null) {
                $bar = ['text' => $text, 'link' => self::text($dress['link'] ?? null)];
            }

            if ($bar !== null) {
                $bar['art'] = $dress['images'] !== [];
            }
        }

        return [
            'bar' => $bar,
            'footer' => is_array($kept['footer'] ?? null) ? $kept['footer'] : null,
            'popup' => $popup,
        ];
    }

    /**
     * The theme live now, its parts in the language asked, or null.
     *
     * @param  array<string, mixed>  $kept
     * @return array{id: string, slug: string, parts: array<string, array{text: string|null, link: string|null, extras: array<string, string>, images: array<string, array<string, array<string, string>>>}>}|null
     */
    public static function theme(array $kept, DateTimeImmutable $now): ?array
    {
        foreach (self::listOf($kept['themes'] ?? null) as $theme) {
            if (self::liveAt($theme, $now)) {
                /** @var array{id: string, slug: string, parts: array<string, array{text: string|null, link: string|null, extras: array<string, string>, images: array<string, array<string, array<string, string>>>}>} */
                return array_diff_key($theme, ['starts' => true, 'ends' => true]);
            }
        }

        return null;
    }

    /**
     * What goes above a product list (content.md §1.9, §1.6 `LISTING`, §1.8 part 8): **one smart bar** - the nearest
     * category's first, then a campaign's, newest first - and **one banner**: the live theme's listing banner ahead of
     * ordinary ones, then the nearest category's first in order, then the first of every list's.
     *
     * @param  array<string, mixed>  $kept
     * @param  list<string>  $categoryPath  the list's category and those above it, from the top; empty for a brand's or a search's list
     * @return array{smartBar: array{text: string, link: string|null}|null, banner: array{link: string|null, alt: string|null, desktop: array<string, array<string, string>>, phone: array<string, array<string, string>>}|null}
     */
    public static function listing(array $kept, string $locale, array $categoryPath, DateTimeImmutable $now): array
    {
        $nearest = array_reverse(array_map('strtolower', $categoryPath));
        $bars = array_values(array_filter(self::listOf($kept['smartBars'] ?? null), static fn (array $bar): bool => self::liveAt($bar, $now)));
        $smartBar = null;

        foreach ([...$nearest, null] as $categoryId) {
            foreach ($bars as $bar) {
                if (($bar['categoryId'] ?? null) === $categoryId) {
                    $smartBar = ['text' => (string) $bar['text'], 'link' => self::text($bar['link'] ?? null)];

                    break 2;
                }
            }
        }

        $banner = null;
        $themed = self::theme($kept, $now)['parts'][ThemePartKind::Listing->value] ?? null;

        if (is_array($themed)) {
            $banner = self::picture($themed['images'], $locale, null, $themed['link'] ?? null);
        }

        if ($banner === null) {
            $banners = array_values(array_filter(self::listOf($kept['listings'] ?? null), static fn (array $banner): bool => self::liveAt($banner, $now)));

            foreach ([...$nearest, null] as $categoryId) {
                foreach ($banners as $candidate) {
                    if (($candidate['categoryId'] ?? null) === $categoryId) {
                        $banner = self::picture(is_array($candidate['images'] ?? null) ? $candidate['images'] : [], $locale, self::text($candidate['alt'] ?? null), self::text($candidate['link'] ?? null));

                        // One whose images failed draws as if it were not there: the next one may.
                        if ($banner !== null) {
                            break 2;
                        }
                    }
                }
            }
        }

        return ['smartBar' => $smartBar, 'banner' => $banner];
    }

    /**
     * A desktop and a phone image, each in the page's language or for any, by kind (`DESKTOP:ar`, `PHONE:any`): null
     * while neither is ready. The home's hero artwork is drawn the same way (H1).
     *
     * @param  array<string, mixed>  $images
     * @return array{link: string|null, alt: string|null, desktop: array<string, array<string, string>>, phone: array<string, array<string, string>>}|null
     */
    public static function picture(array $images, string $locale, ?string $alt, mixed $link): ?array
    {
        $of = static function (string $kind) use ($images, $locale): array {
            $found = $images["{$kind}:{$locale}"] ?? $images["{$kind}:any"] ?? [];

            /** @var array<string, array<string, string>> */
            return is_array($found) ? $found : [];
        };
        $desktop = $of('DESKTOP');
        $phone = $of('PHONE');

        if ($desktop === [] && $phone === []) {
            return null;
        }

        return ['link' => self::text($link), 'alt' => $alt, 'desktop' => $desktop === [] ? $phone : $desktop, 'phone' => $phone === [] ? $desktop : $phone];
    }

    /**
     * @param  array<string, mixed>  $rows
     * @return array<string, mixed>
     */
    private static function inLanguage(array $rows, string $locale): array
    {
        $bar = is_array($rows['bar'] ?? null) ? $rows['bar'] : null;

        return [
            'bar' => $bar === null ? null : ['text' => (string) $bar["text_{$locale}"], 'link' => self::text($bar['link']), 'starts' => self::text($bar['starts']), 'ends' => self::text($bar['ends'])],
            'footer' => is_array($rows['footer'] ?? null) ? self::footer($rows['footer'], $locale) : null,
            'seo' => is_array($rows['seo'] ?? null) ? [
                'site' => self::text($rows['seo']["site_name_{$locale}"] ?? null),
                'pattern' => self::text($rows['seo']["title_pattern_{$locale}"] ?? null),
                'description' => self::text($rows['seo']["description_{$locale}"] ?? null),
                'image' => is_array($rows['seo']['image'] ?? null) ? $rows['seo']['image'] : [],
            ] : null,
            'popups' => array_map(static fn (array $popup): array => [
                'id' => $popup['id'],
                'title' => $popup["title_{$locale}"],
                'text' => $popup["text_{$locale}"],
                'button' => $popup["button_{$locale}"],
                'link' => $popup['link'],
                'delaySeconds' => $popup['delay_seconds'],
                'image' => $popup['image'],
                'starts' => $popup['starts'],
                'ends' => $popup['ends'],
            ], self::listOf($rows['popups'] ?? null)),
            'themes' => array_map(static fn (array $theme): array => [
                'id' => $theme['id'],
                'slug' => $theme['slug'],
                'parts' => array_map(static fn (array $part): array => [
                    'text' => self::text($part["text_{$locale}"] ?? null),
                    'link' => self::text($part['link'] ?? null),
                    'extras' => self::extras(is_array($part['extras'] ?? null) ? $part['extras'] : [], $locale),
                    'images' => is_array($part['images'] ?? null) ? $part['images'] : [],
                ], is_array($theme['parts'] ?? null) ? array_filter($theme['parts'], 'is_array') : []),
                'starts' => $theme['starts'],
                'ends' => $theme['ends'],
            ], self::listOf($rows['themes'] ?? null)),
            'smartBars' => array_map(static fn (array $bar): array => [
                'categoryId' => $bar['categoryId'],
                'text' => (string) $bar["message_{$locale}"],
                'link' => $bar['link'],
                'starts' => $bar['starts'],
                'ends' => $bar['ends'],
            ], self::listOf($rows['smartBars'] ?? null)),
            'listings' => array_map(static fn (array $banner): array => [
                'categoryId' => $banner['categoryId'],
                'link' => $banner['link'],
                'alt' => $banner["alt_{$locale}"],
                'images' => $banner['images'],
                'starts' => $banner['starts'],
                'ends' => $banner['ends'],
            ], self::listOf($rows['listings'] ?? null)),
        ];
    }

    /**
     * A theme part's other words (a promise's), in one language.
     *
     * @param  array<array-key, mixed>  $extras
     * @return array<string, string>
     */
    private static function extras(array $extras, string $locale): array
    {
        $words = [];

        foreach ($extras as $key => $pair) {
            $text = is_array($pair) ? self::text($pair[$locale] ?? null) : null;

            if ($text !== null) {
                $words[(string) $key] = $text;
            }
        }

        return $words;
    }

    /**
     * @param  array<string, mixed>  $footer
     * @return array<string, mixed>
     */
    private static function footer(array $footer, string $locale): array
    {
        $columns = is_array($footer['columns'] ?? null) ? $footer['columns'] : [];

        return [
            'about' => $footer["about_{$locale}"],
            'phone' => $footer['phone'],
            'email' => $footer['email'],
            'address' => $footer["address_{$locale}"],
            'copyright' => $footer["copyright_{$locale}"],
            'columns' => array_map(static fn (array $column): array => [
                'title' => $column['title'][$locale] ?? '',
                'links' => array_map(static fn (array $link): array => ['text' => $link['text'][$locale] ?? '', 'link' => $link['link'] ?? ''], is_array($column['links'] ?? null) ? $column['links'] : []),
            ], $columns),
        ];
    }

    /**
     * The store's SEO defaults in the copy's language (§1.14): the site's name, the title pattern (`:title | :site`), the
     * description and the share image's sizes; every one null (the image empty) while none is saved.
     *
     * @param  array<string, mixed>  $kept
     * @return array{site: string|null, pattern: string|null, description: string|null, image: array<string, array<string, string>>}
     */
    public static function seo(array $kept): array
    {
        $seo = is_array($kept['seo'] ?? null) ? $kept['seo'] : [];
        /** @var array<string, array<string, string>> $image */
        $image = is_array($seo['image'] ?? null) ? $seo['image'] : [];

        return ['site' => self::text($seo['site'] ?? null), 'pattern' => self::text($seo['pattern'] ?? null), 'description' => self::text($seo['description'] ?? null), 'image' => $image];
    }

    /**
     * Whether something kept with its start and end shows at that moment.
     *
     * @param  array<string, mixed>  $item
     */
    public static function liveAt(array $item, DateTimeImmutable $now): bool
    {
        $starts = self::text($item['starts'] ?? null);
        $ends = self::text($item['ends'] ?? null);

        return ($starts === null || CarbonImmutable::parse($starts) <= $now) && ($ends === null || CarbonImmutable::parse($ends) > $now);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
