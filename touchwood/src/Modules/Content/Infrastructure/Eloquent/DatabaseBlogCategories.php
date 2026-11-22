<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Model\BlogCategory;
use Modules\Content\Domain\Repository\BlogCategories;
use stdClass;

/** `BlogCategories` in SQL (content.md §5: `content.blog_categories`). */
final readonly class DatabaseBlogCategories implements BlogCategories
{
    private const string COLUMNS = 'id, store_id, name_ar, name_en, slug_ar, slug_en, position';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $id): ?BlogCategory
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.blog_categories WHERE id = ?', [strtolower($id)]);

        return $row instanceof stdClass ? self::category($row) : null;
    }

    public function lock(string $id): ?BlogCategory
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.blog_categories WHERE id = ? FOR UPDATE', [strtolower($id)]);

        return $row instanceof stdClass ? self::category($row) : null;
    }

    public function ofStore(string $storeId, bool $lock = false): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT '.self::COLUMNS.' FROM content.blog_categories WHERE store_id = ? ORDER BY position, id'.($lock ? ' FOR UPDATE' : ''), [strtolower($storeId)]);

        return array_map(self::category(...), $rows);
    }

    public function add(BlogCategory $category, ?string $by): void
    {
        $now = Rows::at(CarbonImmutable::now()->toDateTimeImmutable());
        $this->db->table('content.blog_categories')->insert([
            'id' => $category->id,
            'store_id' => $category->storeId,
            'position' => $category->position,
            'created_by' => $by,
            'created_at' => $now,
            ...self::written($category, $by),
        ]);
    }

    public function update(BlogCategory $category, ?string $by): void
    {
        $this->db->table('content.blog_categories')->where('id', $category->id)->update(self::written($category, $by));
    }

    public function delete(string $id): void
    {
        $this->db->table('content.blog_categories')->where('id', strtolower($id))->delete();
    }

    public function reorder(array $ids): void
    {
        foreach ($ids as $position => $id) {
            $this->db->table('content.blog_categories')->where('id', strtolower($id))->update(['position' => $position]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function written(BlogCategory $category, ?string $by): array
    {
        return [
            'name_ar' => $category->nameAr,
            'name_en' => $category->nameEn,
            'slug_ar' => $category->slugAr,
            'slug_en' => $category->slugEn,
            'updated_by' => $by,
            'updated_at' => Rows::at(CarbonImmutable::now()->toDateTimeImmutable()),
        ];
    }

    private static function category(stdClass $row): BlogCategory
    {
        return new BlogCategory((string) $row->id, (string) $row->store_id, (string) $row->name_ar, (string) $row->name_en, (string) $row->slug_ar, (string) $row->slug_en, (int) $row->position);
    }
}
