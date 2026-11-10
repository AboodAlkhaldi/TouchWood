<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Query\Shop\ShopReads;
use Modules\Platform\Public\Contracts\PlatformApi;
use stdClass;

/**
 * `ShopReads` in SQL (content.md §1.4, §1.5): what the shop's cached Content is made from, read only on a cold cache.
 * Every image is given as its sizes' addresses (`mediaUrlsOf`, one read for all), empty until they are ready; the
 * original is never served (handoff §5.5).
 */
final readonly class DatabaseShopReads implements ShopReads
{
    public function __construct(
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    public function frame(string $storeId): array
    {
        $storeId = strtolower($storeId);
        $now = Rows::at(Moments::now());
        $bar = $this->db->selectOne('SELECT text_ar, text_en, link, starts_at, ends_at FROM content.bars WHERE store_id = ? AND is_on', [$storeId]);
        $footer = $this->db->selectOne('SELECT about_ar, about_en, phone, email, address_ar, address_en, copyright_ar, copyright_en, columns FROM content.footers WHERE store_id = ?', [$storeId]);

        /** @var list<stdClass> $popups */
        $popups = $this->db->select(
            'SELECT id, title_ar, title_en, text_ar, text_en, button_ar, button_en, link, delay_seconds, media_id, starts_at, ends_at
             FROM content.popups WHERE store_id = ? AND is_scheduled AND (ends_at IS NULL OR ends_at > ?::timestamptz) ORDER BY starts_at, id',
            [$storeId, $now],
        );
        $urls = $this->urls(array_map(static fn (stdClass $popup): ?string => Rows::text($popup->media_id), $popups));

        return [
            'bar' => $bar instanceof stdClass ? ['text_ar' => (string) $bar->text_ar, 'text_en' => (string) $bar->text_en, 'link' => Rows::text($bar->link), 'starts' => self::iso($bar->starts_at), 'ends' => self::iso($bar->ends_at)] : null,
            'footer' => $footer instanceof stdClass ? [...array_map(Rows::text(...), (array) $footer), 'columns' => Rows::decode($footer->columns)] : null,
            'popups' => array_map(static fn (stdClass $popup): array => [
                'id' => (string) $popup->id,
                'title_ar' => (string) $popup->title_ar,
                'title_en' => Rows::text($popup->title_en),
                'text_ar' => (string) $popup->text_ar,
                'text_en' => Rows::text($popup->text_en),
                'button_ar' => Rows::text($popup->button_ar),
                'button_en' => Rows::text($popup->button_en),
                'link' => Rows::text($popup->link),
                'delay_seconds' => (int) $popup->delay_seconds,
                'image' => $urls[(string) $popup->media_id] ?? [],
                'starts' => self::iso($popup->starts_at),
                'ends' => self::iso($popup->ends_at),
            ], $popups),
        ];
    }

    public function home(string $storeId): array
    {
        $storeId = strtolower($storeId);
        $now = Rows::at(Moments::now());

        /** @var list<stdClass> $sections */
        $sections = $this->db->select('SELECT id, type, settings FROM content.home_sections WHERE store_id = ? AND is_visible ORDER BY position, id', [$storeId]);

        /** @var list<stdClass> $logos */
        $logos = $this->db->select(
            "SELECT m.section_id, m.media_id, m.name_ar, m.name_en, m.link FROM content.home_section_media m JOIN content.home_sections s ON s.id = m.section_id
             WHERE s.store_id = ? AND s.is_visible AND m.slot = 'LOGO' ORDER BY m.position",
            [$storeId],
        );

        /** @var list<stdClass> $banners */
        $banners = $this->db->select(
            "SELECT id, placement, link, alt_ar, alt_en, starts_at, ends_at FROM content.banners
             WHERE store_id = ? AND placement IN ('HOME_HERO', 'HOME_PHONE') AND is_scheduled AND (ends_at IS NULL OR ends_at > ?::timestamptz) ORDER BY position, id",
            [$storeId, $now],
        );

        /** @var list<stdClass> $images */
        $images = $banners === [] ? [] : $this->db->select('SELECT banner_id, kind, locale, media_id FROM content.banner_images WHERE banner_id IN ('.implode(', ', array_fill(0, count($banners), '?')).')', array_map(static fn (stdClass $banner): string => (string) $banner->id, $banners));

        /** @var list<stdClass> $projects */
        $projects = $this->db->select(
            "SELECT p.id, ar.title AS title_ar, en.title AS title_en, ar.slug AS slug_ar, en.slug AS slug_en, ar.city AS line_ar, en.city AS line_en,
                    (SELECT media_id FROM content.project_photos WHERE project_id = p.id ORDER BY position LIMIT 1) AS media_id
             FROM content.projects p JOIN content.project_translations ar ON ar.project_id = p.id AND ar.locale = 'ar' JOIN content.project_translations en ON en.project_id = p.id AND en.locale = 'en'
             WHERE p.store_id = ? AND p.status = 'PUBLISHED' ORDER BY p.published_at DESC, p.id DESC LIMIT 1",
            [$storeId],
        );

        /** @var list<stdClass> $posts */
        $posts = $this->db->select(
            "SELECT p.id, ar.title AS title_ar, en.title AS title_en, ar.slug AS slug_ar, en.slug AS slug_en, ar.summary AS line_ar, en.summary AS line_en, p.cover_media_id AS media_id
             FROM content.blog_posts p JOIN content.blog_post_translations ar ON ar.post_id = p.id AND ar.locale = 'ar' JOIN content.blog_post_translations en ON en.post_id = p.id AND en.locale = 'en'
             WHERE p.store_id = ? AND p.status = 'PUBLISHED' ORDER BY p.published_at DESC, p.id DESC LIMIT 2",
            [$storeId],
        );

        $urls = $this->urls([
            ...array_map(static fn (stdClass $logo): string => (string) $logo->media_id, $logos),
            ...array_map(static fn (stdClass $image): string => (string) $image->media_id, $images),
            ...array_map(static fn (stdClass $document): ?string => Rows::text($document->media_id), [...$projects, ...$posts]),
        ]);

        $bannerImages = [];

        foreach ($images as $image) {
            $bannerImages[(string) $image->banner_id]["{$image->kind}:{$image->locale}"] = $urls[(string) $image->media_id] ?? [];
        }

        $document = static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'title_ar' => (string) $row->title_ar,
            'title_en' => (string) $row->title_en,
            'slug_ar' => (string) $row->slug_ar,
            'slug_en' => (string) $row->slug_en,
            'line_ar' => Rows::text($row->line_ar),
            'line_en' => Rows::text($row->line_en),
            'image' => $urls[(string) $row->media_id] ?? [],
        ];

        return [
            'sections' => array_map(static fn (stdClass $section): array => [
                'type' => (string) $section->type,
                'settings' => Rows::decode($section->settings),
                'logos' => array_values(array_map(static fn (stdClass $logo): array => [
                    'name_ar' => (string) $logo->name_ar,
                    'name_en' => Rows::text($logo->name_en),
                    'link' => Rows::text($logo->link),
                    'image' => $urls[(string) $logo->media_id] ?? [],
                ], array_filter($logos, static fn (stdClass $logo): bool => $logo->section_id === $section->id))),
            ], $sections),
            'banners' => array_map(static fn (stdClass $banner): array => [
                'id' => (string) $banner->id,
                'placement' => (string) $banner->placement,
                'link' => Rows::text($banner->link),
                'alt_ar' => Rows::text($banner->alt_ar),
                'alt_en' => Rows::text($banner->alt_en),
                'images' => $bannerImages[(string) $banner->id] ?? [],
                'starts' => self::iso($banner->starts_at),
                'ends' => self::iso($banner->ends_at),
            ], $banners),
            'projects' => array_map($document, $projects),
            'posts' => array_map($document, $posts),
        ];
    }

    /**
     * @param  list<string|null>  $ids
     * @return array<string, array<string, array<string, string>>> media id => its sizes' addresses
     */
    private function urls(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?string $id): bool => $id !== null)));

        return array_map(static fn ($urls): array => $urls->variants, $ids === [] ? [] : $this->platform->mediaUrlsOf($ids));
    }

    private static function iso(mixed $value): ?string
    {
        return Rows::moment($value)?->format(DATE_ATOM);
    }
}
