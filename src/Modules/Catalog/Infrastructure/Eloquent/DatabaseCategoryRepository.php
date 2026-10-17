<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Model\Category;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\ValueObject\ListPosition;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\Slugs;
use Modules\Catalog\Public\Enums\CatalogItem;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

final readonly class DatabaseCategoryRepository implements CategoryRepository
{
    private const string TABLE = 'catalog.categories';

    private const string RANKS = 'catalog.store_category_ranks';

    private SlugHistory $slugs;

    public function __construct(
        private ConnectionInterface $db,
    ) {
        $this->slugs = new SlugHistory($db, 'catalog.category_slugs', 'category_id');
    }

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $categoryId): ?Category
    {
        return $this->read($categoryId, false);
    }

    public function byId(string $categoryId): ?Category
    {
        return $this->read($categoryId, true);
    }

    public function childrenOf(?string $categoryId): array
    {
        if ($categoryId !== null && ! Ulids::valid($categoryId)) {
            return [];
        }

        $query = $this->db->table(self::TABLE);
        $query = $categoryId === null ? $query->whereNull('parent_id') : $query->where('parent_id', strtolower($categoryId));

        return $this->toCategories($query->orderBy('name_en')->orderBy('id')->get()->all());
    }

    public function idsBelow(string $categoryId): array
    {
        if (! Ulids::valid($categoryId)) {
            return [];
        }

        $rows = $this->db->select(
            'WITH RECURSIVE below AS (
                SELECT id FROM catalog.categories WHERE parent_id = ?
                UNION
                SELECT c.id FROM catalog.categories c JOIN below b ON c.parent_id = b.id
            ) SELECT id FROM below ORDER BY id',
            [strtolower($categoryId)],
            false,
        );

        return array_values(array_map(static fn (mixed $row): string => $row instanceof stdClass ? (string) $row->id : '', $rows));
    }

    public function slugTaken(string $locale, string $slug, ?string $exceptCategoryId = null): bool
    {
        return $this->slugs->taken($locale, $slug, $exceptCategoryId);
    }

    public function add(Category $category): void
    {
        $now = CarbonImmutable::now();
        $this->db->table(self::TABLE)->insert(['id' => $category->id(), ...self::toRow($category), 'created_at' => $now, 'updated_at' => $now]);
        $this->slugs->record($category->id(), $category->slugs());
    }

    public function update(Category $category): void
    {
        $this->db->table(self::TABLE)->where('id', $category->id())->update([...self::toRow($category), 'updated_at' => CarbonImmutable::now()]);
        $this->slugs->record($category->id(), $category->slugs());
    }

    public function delete(string $categoryId): void
    {
        // Another module's row written since its usage check refuses it here (amendment 19).
        ReferencedDelete::run($this->db, CatalogItem::Category, $categoryId, fn () => $this->db->table(self::TABLE)->where('id', strtolower($categoryId))->delete());
    }

    public function all(): array
    {
        return $this->toCategories($this->db->table(self::TABLE)->orderBy('name_en')->orderBy('id')->get()->all());
    }

    public function withImage(string $mediaId): array
    {
        return array_values(array_map('strval', $this->db->table(self::TABLE)->where('image_media_id', strtolower($mediaId))->orderBy('id')->pluck('id')->all()));
    }

    public function placeIn(string $categoryId, array $storeIds, int $rank): void
    {
        foreach ($storeIds as $storeId) {
            $this->db->table(self::RANKS)->upsert(
                [['store_id' => strtolower($storeId), 'category_id' => strtolower($categoryId), 'rank' => $rank]],
                ['store_id', 'category_id'],
                ['rank'],
            );
        }
    }

    public function placeEach(string $storeId, array $ranks): void
    {
        if ($ranks === []) {
            return;
        }

        $rows = [];

        foreach ($ranks as $categoryId => $rank) {
            $rows[] = ['store_id' => strtolower($storeId), 'category_id' => strtolower((string) $categoryId), 'rank' => $rank];
        }

        $this->db->table(self::RANKS)->upsert($rows, ['store_id', 'category_id'], ['rank']);
    }

    public function placeLast(string $categoryId, ?string $parentId, array $storeIds, ?string $baseStoreId): array
    {
        $categoryId = strtolower($categoryId);
        // Its own places before, store by store: none for a category just added.
        $own = array_map('intval', $this->db->table(self::RANKS)->where('category_id', $categoryId)->pluck('rank', 'store_id')->all());
        $written = [];

        foreach ($storeIds as $storeId) {
            $storeId = strtolower($storeId);
            $siblings = $this->siblingsIn($storeId, $baseStoreId, $parentId);
            unset($siblings[$categoryId]);
            $places = array_values(array_filter(array_column($siblings, 1), static fn (?int $place): bool => $place !== null));
            $rank = ($places === [] ? 0 : max($places)) + 1;

            // Only siblings placed by hand near the cap reach it: numbered again in the order the
            // menu shows them, every one keeps its place among the others.
            if ($rank > ListPosition::MAX) {
                $at = 0;
                $ranks = [];

                foreach ($siblings as $sibling => [$siblingOwn]) {
                    if ($siblingOwn !== ++$at) {
                        $ranks[$sibling] = $at;
                        $written[] = [$sibling, $storeId, $siblingOwn, $at];
                    }
                }

                $this->placeEach($storeId, $ranks);
                $rank = ListPosition::check($at + 1, 'rank');
            }

            $this->placeIn($categoryId, [$storeId], $rank);
            $written[] = [$categoryId, $storeId, $own[$storeId] ?? null, $rank];
        }

        return $written;
    }

    public function rankIn(string $storeId, string $categoryId): ?int
    {
        if (! Ulids::valid($storeId) || ! Ulids::valid($categoryId)) {
            return null;
        }

        $rank = $this->db->table(self::RANKS)->where('store_id', strtolower($storeId))->where('category_id', strtolower($categoryId))->value('rank');

        return $rank === null ? null : (int) $rank;
    }

    public function siblingsIn(string $storeId, ?string $baseStoreId, ?string $parentId): array
    {
        $storeId = strtolower($storeId);
        $rows = $this->db->select(
            'SELECT c.id, r.rank AS own, COALESCE(r.rank, b.rank) AS place FROM '.self::TABLE.' c'
            .' LEFT JOIN '.self::RANKS.' r ON r.store_id = ? AND r.category_id = c.id'
            .' LEFT JOIN '.self::RANKS.' b ON b.store_id = ? AND b.category_id = c.id'
            .' WHERE '.($parentId === null ? 'c.parent_id IS NULL' : 'c.parent_id = ?')
            .' ORDER BY place NULLS LAST, c.name_en, c.id',
            [$storeId, $baseStoreId === null ? $storeId : strtolower($baseStoreId), ...($parentId === null ? [] : [strtolower($parentId)])],
        );
        $siblings = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $siblings[(string) $row->id] = [$row->own === null ? null : (int) $row->own, $row->place === null ? null : (int) $row->place];
            }
        }

        return $siblings;
    }

    private function read(string $categoryId, bool $lock): ?Category
    {
        if (! Ulids::valid($categoryId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($categoryId))->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $row instanceof stdClass ? $this->toCategories([$row])[0] : null;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<Category>
     */
    private function toCategories(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass));
        $slugs = $this->slugs->currentOf(array_map(static fn (stdClass $row): string => (string) $row->id, $rows));

        return array_map(static fn (stdClass $row): Category => Category::reconstitute(
            (string) $row->id,
            $row->parent_id === null ? null : (string) $row->parent_id,
            LocalizedName::reconstitute((string) $row->name_ar, (string) $row->name_en),
            Slugs::reconstitute($slugs[(string) $row->id]['ar'] ?? '', $slugs[(string) $row->id]['en'] ?? ''),
            $row->image_media_id === null ? null : (string) $row->image_media_id,
            (bool) $row->is_active,
            (bool) $row->deactivated_with_parent,
        ), $rows);
    }

    /**
     * @return array<string, string|bool|null>
     */
    private static function toRow(Category $category): array
    {
        return [
            'parent_id' => $category->parentId(),
            'name_ar' => $category->name()->ar,
            'name_en' => $category->name()->en,
            'image_media_id' => $category->imageMediaId(),
            'is_active' => $category->isActive(),
            'deactivated_with_parent' => $category->deactivatedWithParent(),
        ];
    }
}
