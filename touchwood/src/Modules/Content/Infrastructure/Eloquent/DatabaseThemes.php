<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Model\Theme;
use Modules\Content\Domain\Model\ThemePart;
use Modules\Content\Domain\Model\ThemePartKind;
use Modules\Content\Domain\Repository\Themes;
use stdClass;

/** `Themes` in SQL (content.md §5: `content.themes`, `content.theme_stores`, `content.theme_parts`, `content.theme_part_images`). */
final readonly class DatabaseThemes implements Themes
{
    private const string COLUMNS = 'id, slug, name_ar, name_en, is_scheduled, starts_at_local, ends_at_local, version, updated_by, updated_at';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $id): ?Theme
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.themes WHERE id = ?', [strtolower($id)]);

        return $row instanceof stdClass ? $this->theme($row) : null;
    }

    public function lock(string $id): ?Theme
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.themes WHERE id = ? FOR UPDATE', [strtolower($id)]);

        return $row instanceof stdClass ? $this->theme($row) : null;
    }

    public function slugTaken(string $slug): bool
    {
        return $this->db->scalar('SELECT 1 FROM content.themes WHERE slug = ?', [$slug]) !== null;
    }

    public function add(Theme $theme): void
    {
        $this->db->table('content.themes')->insert([
            'id' => $theme->id,
            'slug' => $theme->slug,
            'created_by' => $theme->updatedBy,
            'created_at' => Rows::at($theme->updatedAt),
            ...self::written($theme),
        ]);
        $this->writeStores($theme);
    }

    public function update(Theme $theme): void
    {
        $this->db->table('content.themes')->where('id', $theme->id)->update(self::written($theme));
        $this->writeStores($theme);
    }

    public function delete(string $id): void
    {
        $this->db->table('content.themes')->where('id', strtolower($id))->delete();
    }

    public function scheduledIn(string $storeId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            'SELECT '.self::COLUMNS.' FROM content.themes WHERE is_scheduled AND id IN (SELECT theme_id FROM content.theme_stores WHERE store_id = ?) ORDER BY id',
            [strtolower($storeId)],
        );

        return $this->themes($rows);
    }

    public function partsOf(string $themeId): array
    {
        $themeId = strtolower($themeId);
        /** @var list<stdClass> $parts */
        $parts = $this->db->select('SELECT part, text_ar, text_en, link, extras FROM content.theme_parts WHERE theme_id = ?', [$themeId]);
        /** @var list<stdClass> $images */
        $images = $this->db->select('SELECT part, slot, locale, media_id FROM content.theme_part_images WHERE theme_id = ? ORDER BY part, slot, locale', [$themeId]);
        $byPart = [];

        foreach ($images as $image) {
            $byPart[(string) $image->part]["{$image->slot}:{$image->locale}"] = (string) $image->media_id;
        }

        $found = [];

        foreach ($parts as $row) {
            $kind = ThemePartKind::from((string) $row->part);
            /** @var array<string, array{ar: string, en: string|null}> $extras */
            $extras = Rows::decode($row->extras);
            $found[$kind->value] = new ThemePart($kind, Rows::text($row->text_ar), Rows::text($row->text_en), Rows::text($row->link), $extras, $byPart[$kind->value] ?? []);
        }

        // §1.8's order.
        return array_values(array_filter(array_map(static fn (ThemePartKind $kind): ?ThemePart => $found[$kind->value] ?? null, ThemePartKind::cases())));
    }

    public function replaceParts(string $themeId, array $parts): void
    {
        $themeId = strtolower($themeId);
        $this->db->table('content.theme_parts')->where('theme_id', $themeId)->delete();

        ForeignKeyRefusals::guard(function () use ($themeId, $parts): void {
            foreach ($parts as $part) {
                if ($part->isEmpty()) {
                    continue;
                }

                $this->db->table('content.theme_parts')->insert([
                    'theme_id' => $themeId,
                    'part' => $part->part->value,
                    'text_ar' => $part->textAr,
                    'text_en' => $part->textEn,
                    'link' => $part->link,
                    'extras' => Rows::json($part->extras, object: true),
                ]);

                foreach ($part->images as $key => $mediaId) {
                    [$slot, $locale] = explode(':', $key, 2);
                    $this->db->table('content.theme_part_images')->insert(['theme_id' => $themeId, 'part' => $part->part->value, 'slot' => $slot, 'locale' => $locale, 'media_id' => strtolower($mediaId)]);
                }
            }
        });
    }

    public function isLinked(string $themeId): bool
    {
        return $this->db->scalar('SELECT 1 FROM content.campaigns WHERE theme_id = ? LIMIT 1', [strtolower($themeId)]) !== null;
    }

    private function writeStores(Theme $theme): void
    {
        $this->db->table('content.theme_stores')->where('theme_id', $theme->id)->delete();
        $rows = [];

        foreach ($theme->stores as $storeId => [$starts, $ends]) {
            $rows[] = ['theme_id' => $theme->id, 'store_id' => $storeId, 'starts_at' => Rows::at($starts), 'ends_at' => Rows::at($ends)];
        }

        if ($rows !== []) {
            $this->db->table('content.theme_stores')->insert($rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function written(Theme $theme): array
    {
        return [
            'name_ar' => $theme->nameAr,
            'name_en' => $theme->nameEn,
            'is_scheduled' => $theme->isScheduled,
            'starts_at_local' => $theme->startsLocal.':00',
            'ends_at_local' => $theme->endsLocal.':00',
            'version' => $theme->version,
            'updated_by' => $theme->updatedBy,
            'updated_at' => Rows::at($theme->updatedAt),
        ];
    }

    private function theme(stdClass $row): Theme
    {
        return $this->themes([$row])[0];
    }

    /**
     * Rows as themes, their stores' moments read in one query.
     *
     * @param  list<stdClass>  $rows
     * @return list<Theme>
     */
    private function themes(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        /** @var list<stdClass> $stores */
        $stores = $this->db->select('SELECT theme_id, store_id, starts_at, ends_at FROM content.theme_stores WHERE theme_id IN ('.implode(', ', array_fill(0, count($ids), '?')).') ORDER BY store_id', $ids);
        $byTheme = [];

        foreach ($stores as $store) {
            $byTheme[(string) $store->theme_id][(string) $store->store_id] = [self::moment($store->starts_at), self::moment($store->ends_at)];
        }

        return array_map(static fn (stdClass $row): Theme => new Theme(
            (string) $row->id,
            (string) $row->slug,
            (string) $row->name_ar,
            Rows::text($row->name_en),
            (bool) $row->is_scheduled,
            substr((string) $row->starts_at_local, 0, 16),
            substr((string) $row->ends_at_local, 0, 16),
            $byTheme[(string) $row->id] ?? [],
            (int) $row->version,
            Rows::text($row->updated_by),
            Rows::moment($row->updated_at) ?? CarbonImmutable::now()->toDateTimeImmutable(),
        ), $rows);
    }

    private static function moment(mixed $value): DateTimeImmutable
    {
        return Rows::moment($value) ?? CarbonImmutable::now()->toDateTimeImmutable();
    }
}
