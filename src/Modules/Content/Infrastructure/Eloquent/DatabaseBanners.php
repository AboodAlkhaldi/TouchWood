<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Model\Banner;
use Modules\Content\Domain\Model\BannerPlacement;
use Modules\Content\Domain\Repository\Banners;
use Modules\Content\Domain\ValueObject\Schedule;
use stdClass;

/** `Banners` in SQL (content.md §5: `content.banners`, `content.banner_images`). */
final readonly class DatabaseBanners implements Banners
{
    private const string COLUMNS = 'id, store_id, placement, category_id, name, link, has_words, alt_ar, alt_en, is_scheduled, starts_at, ends_at, position, version, updated_by, updated_at';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $id): ?Banner
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.banners WHERE id = ?', [strtolower($id)]);

        return $row instanceof stdClass ? $this->banner($row) : null;
    }

    public function lock(string $id): ?Banner
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.banners WHERE id = ? FOR UPDATE', [strtolower($id)]);

        return $row instanceof stdClass ? $this->banner($row) : null;
    }

    public function inPlacement(string $storeId, BannerPlacement $placement, bool $lock = false): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT '.self::COLUMNS.' FROM content.banners WHERE store_id = ? AND placement = ? ORDER BY position, id'.($lock ? ' FOR UPDATE' : ''), [strtolower($storeId), $placement->value]);

        return array_map($this->banner(...), $rows);
    }

    public function add(Banner $banner): void
    {
        $this->db->table('content.banners')->insert([
            'id' => $banner->id,
            'store_id' => $banner->storeId,
            'placement' => $banner->placement->value,
            'created_by' => $banner->updatedBy,
            'created_at' => Rows::at($banner->updatedAt),
            ...self::written($banner),
        ]);
        $this->writeImages($banner);
    }

    public function update(Banner $banner): void
    {
        $this->db->table('content.banners')->where('id', $banner->id)->update(self::written($banner));
        $this->writeImages($banner);
    }

    public function delete(string $id): void
    {
        $this->db->table('content.banners')->where('id', strtolower($id))->delete();
    }

    public function reorder(array $ids): void
    {
        foreach ($ids as $position => $id) {
            $this->db->table('content.banners')->where('id', strtolower($id))->update(['position' => $position]);
        }
    }

    private function writeImages(Banner $banner): void
    {
        $this->db->table('content.banner_images')->where('banner_id', $banner->id)->delete();

        foreach ($banner->images as $slot => $mediaId) {
            [$kind, $locale] = explode(':', $slot, 2);
            $this->db->table('content.banner_images')->insert(['banner_id' => $banner->id, 'kind' => $kind, 'locale' => $locale, 'media_id' => $mediaId]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function written(Banner $banner): array
    {
        return [
            'category_id' => $banner->categoryId,
            'name' => $banner->name,
            'link' => $banner->link,
            'has_words' => $banner->hasWords,
            'alt_ar' => $banner->altAr,
            'alt_en' => $banner->altEn,
            'is_scheduled' => $banner->schedule->isScheduled,
            'starts_at' => Rows::at($banner->schedule->startsAt),
            'ends_at' => Rows::at($banner->schedule->endsAt),
            'position' => $banner->position,
            'version' => $banner->version,
            'updated_by' => $banner->updatedBy,
            'updated_at' => Rows::at($banner->updatedAt),
        ];
    }

    private function banner(stdClass $row): Banner
    {
        $images = [];

        foreach ($this->db->select('SELECT kind, locale, media_id FROM content.banner_images WHERE banner_id = ? ORDER BY kind, locale', [(string) $row->id]) as $image) {
            /** @var stdClass $image */
            $images["{$image->kind}:{$image->locale}"] = (string) $image->media_id;
        }

        return new Banner(
            (string) $row->id,
            (string) $row->store_id,
            BannerPlacement::from((string) $row->placement),
            Rows::text($row->category_id),
            (string) $row->name,
            Rows::text($row->link),
            (bool) $row->has_words,
            Rows::text($row->alt_ar),
            Rows::text($row->alt_en),
            new Schedule((bool) $row->is_scheduled, Rows::moment($row->starts_at), Rows::moment($row->ends_at)),
            (int) $row->position,
            $images,
            (int) $row->version,
            Rows::text($row->updated_by),
            Rows::moment($row->updated_at) ?? CarbonImmutable::now()->toDateTimeImmutable(),
        );
    }
}
