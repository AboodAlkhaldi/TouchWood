<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Model\SeoDefaults;
use Modules\Content\Domain\Repository\StoreSeo;
use stdClass;

/**
 * `StoreSeo` in SQL (content.md §5: `content.seo_defaults`, `content.sitemaps`). A store's first save of its defaults
 * holds an advisory lock named for it, as there is no row yet to lock.
 */
final readonly class DatabaseStoreSeo implements StoreSeo
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function defaults(string $storeId, bool $lock = false): ?SeoDefaults
    {
        $storeId = strtolower($storeId);

        if ($lock) {
            $this->db->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['content:seo:'.$storeId]);
        }

        $row = $this->db->selectOne('SELECT * FROM content.seo_defaults WHERE store_id = ?'.($lock ? ' FOR UPDATE' : ''), [$storeId]);

        if (! $row instanceof stdClass) {
            return null;
        }

        return new SeoDefaults(
            $storeId,
            (string) $row->site_name_ar,
            (string) $row->site_name_en,
            (string) $row->title_pattern_ar,
            (string) $row->title_pattern_en,
            (string) $row->description_ar,
            (string) $row->description_en,
            Rows::text($row->share_media_id),
            self::lines($row->robots_extra),
            Rows::text($row->updated_by),
            Rows::moment($row->updated_at) ?? throw new \LogicException('Defaults have their moment.'),
        );
    }

    public function saveDefaults(SeoDefaults $defaults): void
    {
        // A share image deleted between its check and this write is refused as the check would have said.
        ForeignKeyRefusals::guard(fn () => $this->db->table('content.seo_defaults')->updateOrInsert(['store_id' => $defaults->storeId], [
            'site_name_ar' => $defaults->siteNameAr,
            'site_name_en' => $defaults->siteNameEn,
            'title_pattern_ar' => $defaults->titlePatternAr,
            'title_pattern_en' => $defaults->titlePatternEn,
            'description_ar' => $defaults->descriptionAr,
            'description_en' => $defaults->descriptionEn,
            'share_media_id' => $defaults->shareMediaId,
            'robots_extra' => implode("\n", $defaults->robotsLines),
            'updated_by' => $defaults->updatedBy,
            'updated_at' => Rows::at($defaults->updatedAt),
        ]));
    }

    public function robotsLines(): array
    {
        $lines = [];

        foreach ($this->db->select("SELECT store_id, robots_extra FROM content.seo_defaults WHERE robots_extra <> '' ORDER BY store_id") as $row) {
            $lines[(string) $row->store_id] = self::lines($row->robots_extra);
        }

        return $lines;
    }

    public function sitemapParts(): array
    {
        $parts = [];

        foreach ($this->db->select('SELECT * FROM content.sitemaps ORDER BY store_id, part') as $row) {
            $parts[(string) $row->store_id][(int) $row->part] = [
                'file_key' => (string) $row->file_key,
                'urls' => (int) $row->urls,
                'built_at' => Rows::moment($row->built_at) ?? throw new \LogicException('A part has its moment.'),
            ];
        }

        return $parts;
    }

    public function sitemapFile(string $storeId, int $part): ?string
    {
        $key = $this->db->table('content.sitemaps')->where('store_id', strtolower($storeId))->where('part', $part)->value('file_key');

        return $key === null ? null : (string) $key;
    }

    public function replaceSitemap(string $storeId, array $parts, DateTimeImmutable $at): array
    {
        $storeId = strtolower($storeId);
        $old = array_values(array_map(strval(...), $this->db->table('content.sitemaps')->where('store_id', $storeId)->lockForUpdate()->pluck('file_key')->all()));
        $this->db->table('content.sitemaps')->where('store_id', $storeId)->delete();

        foreach ($parts as $part => $file) {
            $this->db->table('content.sitemaps')->insert(['store_id' => $storeId, 'part' => $part, 'file_key' => $file['file_key'], 'urls' => $file['urls'], 'built_at' => Rows::at($at)]);
        }

        return array_values(array_diff($old, array_column($parts, 'file_key')));
    }

    public function removeSitemapsBut(array $keep): array
    {
        $query = $this->db->table('content.sitemaps')->whereNotIn('store_id', array_map(strtolower(...), $keep));
        $removed = array_values(array_map(strval(...), (clone $query)->lockForUpdate()->pluck('file_key')->all()));
        $query->delete();

        return $removed;
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $text): array
    {
        return array_values(array_filter(explode("\n", (string) $text), static fn (string $line): bool => $line !== ''));
    }
}
