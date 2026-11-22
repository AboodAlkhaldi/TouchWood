<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Model\HomeLogo;
use Modules\Content\Domain\Model\HomeSection;
use Modules\Content\Domain\Model\HomeSectionType;
use Modules\Content\Domain\Repository\HomeSections;
use stdClass;

/** `HomeSections` in SQL (content.md §5: `content.home_sections`, `content.home_section_media`). */
final readonly class DatabaseHomeSections implements HomeSections
{
    private const string COLUMNS = 'id, store_id, type, position, is_visible, settings, version, updated_by, updated_at';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $id): ?HomeSection
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.home_sections WHERE id = ?', [strtolower($id)]);

        return $row instanceof stdClass ? self::section($row) : null;
    }

    public function lock(string $id): ?HomeSection
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.home_sections WHERE id = ? FOR UPDATE', [strtolower($id)]);

        return $row instanceof stdClass ? self::section($row) : null;
    }

    public function ofStore(string $storeId, bool $lock = false): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT '.self::COLUMNS.' FROM content.home_sections WHERE store_id = ? ORDER BY position, id'.($lock ? ' FOR UPDATE' : ''), [strtolower($storeId)]);

        return array_map(self::section(...), $rows);
    }

    public function add(HomeSection $section): void
    {
        $this->db->table('content.home_sections')->insert([
            'id' => $section->id,
            'store_id' => $section->storeId,
            'type' => $section->type->value,
            'created_at' => Rows::at($section->updatedAt),
            ...self::written($section),
        ]);
    }

    public function update(HomeSection $section): void
    {
        $this->db->table('content.home_sections')->where('id', $section->id)->update(self::written($section));
    }

    public function delete(string $id): void
    {
        $this->db->table('content.home_sections')->where('id', strtolower($id))->delete();
    }

    public function reorder(array $ids): void
    {
        foreach ($ids as $position => $id) {
            $this->db->table('content.home_sections')->where('id', strtolower($id))->update(['position' => $position]);
        }
    }

    public function logos(string $sectionId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select("SELECT media_id, name_ar, name_en, link FROM content.home_section_media WHERE section_id = ? AND slot = 'LOGO' ORDER BY position", [strtolower($sectionId)]);

        return array_map(static fn (stdClass $row): HomeLogo => new HomeLogo((string) $row->media_id, (string) $row->name_ar, Rows::text($row->name_en), Rows::text($row->link)), $rows);
    }

    public function replaceLogos(string $sectionId, array $logos): void
    {
        ForeignKeyRefusals::guard(function () use ($sectionId, $logos): void {
            $this->db->table('content.home_section_media')->where('section_id', strtolower($sectionId))->where('slot', 'LOGO')->delete();

            foreach ($logos as $position => $logo) {
                $this->db->table('content.home_section_media')->insert([
                    'section_id' => strtolower($sectionId),
                    'slot' => 'LOGO',
                    'position' => $position,
                    'media_id' => $logo->mediaId,
                    'name_ar' => $logo->nameAr,
                    'name_en' => $logo->nameEn,
                    'link' => $logo->link,
                ]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function written(HomeSection $section): array
    {
        return [
            'position' => $section->position,
            'is_visible' => $section->isVisible,
            'settings' => Rows::json($section->settings, object: true),
            'version' => $section->version,
            'updated_by' => $section->updatedBy,
            'updated_at' => Rows::at($section->updatedAt),
        ];
    }

    private static function section(stdClass $row): HomeSection
    {
        /** @var array<string, mixed> $settings */
        $settings = Rows::decode($row->settings);

        return new HomeSection(
            (string) $row->id,
            (string) $row->store_id,
            HomeSectionType::from((string) $row->type),
            (int) $row->position,
            (bool) $row->is_visible,
            $settings,
            (int) $row->version,
            Rows::text($row->updated_by),
            Rows::moment($row->updated_at) ?? CarbonImmutable::now()->toDateTimeImmutable(),
        );
    }
}
