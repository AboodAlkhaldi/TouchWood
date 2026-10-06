<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\ValueObject\ProductName;
use Modules\Catalog\Domain\ValueObject\ProductSlugs;
use Modules\Catalog\Domain\ValueObject\StructuredText;
use Modules\Catalog\Public\Enums\ProductStage;
use stdClass;

final readonly class DatabaseProductRepository implements ProductRepository
{
    private const string TABLE = 'catalog.products';

    private const string CODES = 'catalog.product_codes';

    private SlugHistory $slugs;

    public function __construct(
        private ConnectionInterface $db,
    ) {
        $this->slugs = new SlugHistory($db, 'catalog.product_slugs', 'product_id');
    }

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $productId): ?Product
    {
        return $this->read($productId, false);
    }

    public function byId(string $productId): ?Product
    {
        return $this->read($productId, true);
    }

    public function slugTaken(string $locale, string $slug, ?string $exceptProductId = null): bool
    {
        return $this->slugs->taken($locale, $slug, $exceptProductId);
    }

    public function add(Product $product): void
    {
        $now = CarbonImmutable::now();
        $this->db->table(self::TABLE)->insert(['id' => $product->id(), ...self::toRow($product), 'created_at' => $now, 'updated_at' => $now]);
        $this->slugs->recordLocales($product->id(), $product->slugs()->byLocale());
    }

    public function update(Product $product): void
    {
        $this->db->table(self::TABLE)->where('id', $product->id())->update([...self::toRow($product), 'updated_at' => CarbonImmutable::now()]);
        $this->slugs->recordLocales($product->id(), $product->slugs()->byLocale());
    }

    public function delete(string $productId): void
    {
        $this->db->table(self::TABLE)->where('id', strtolower($productId))->delete();
    }

    public function codeHolder(string $code): ?string
    {
        $holder = $this->db->table(self::CODES)->where('code', $code)->value('product_id');

        return $holder === null ? null : (string) $holder;
    }

    public function holdCode(string $productId, string $code): void
    {
        $held = $this->db->table(self::CODES)->where('product_id', $productId)->where('code', $code)->exists();

        if (! $held) {
            // Another product holding it fails the primary key: the handler asks first (CodeTaken).
            $this->db->table(self::CODES)->insert(['code' => $code, 'product_id' => $productId, 'created_at' => CarbonImmutable::now()]);
        }
    }

    public function releaseCode(string $productId, string $code): void
    {
        $this->db->table(self::CODES)->where('product_id', $productId)->where('code', $code)->delete();
    }

    public function codesOf(string $productId): array
    {
        if (! Ulids::valid($productId)) {
            return [];
        }

        return array_values(array_map('strval', $this->db->table(self::CODES)->where('product_id', strtolower($productId))->orderBy('code')->pluck('code')->all()));
    }

    public function anyInCategory(string $categoryId): bool
    {
        return $this->anyWith('category_id', $categoryId);
    }

    public function anyWithBrand(string $brandId): bool
    {
        return $this->anyWith('brand_id', $brandId);
    }

    public function anyWithWarranty(string $warrantyId): bool
    {
        return $this->anyWith('warranty_id', $warrantyId);
    }

    public function anyWithAttributeSet(string $setId): bool
    {
        return $this->anyWith('attribute_set_id', $setId);
    }

    public function variantsOnSet(string $setId): bool
    {
        return Ulids::valid($setId) && $this->db->table(self::TABLE.' as p')
            ->join('catalog.variants as v', 'v.product_id', '=', 'p.id')
            ->where('p.attribute_set_id', strtolower($setId))
            ->exists();
    }

    public function gallery(string $productId): array
    {
        return $this->ordered('catalog.product_photos', 'product_id', $productId, 'media_id');
    }

    public function replaceGallery(string $productId, array $mediaIds): void
    {
        // A photo that stays is moved, never written again: a new row would take a key lock on its
        // media row, which deleting that file holds while it waits for the products' lock.
        $this->db->table('catalog.product_photos')->where('product_id', $productId)->whereNotIn('media_id', $mediaIds)->delete();
        $held = $this->db->table('catalog.product_photos')->where('product_id', $productId)->pluck('position', 'media_id')->all();

        foreach ($mediaIds as $position => $mediaId) {
            if (! array_key_exists($mediaId, $held)) {
                $this->db->table('catalog.product_photos')->insert(['product_id' => $productId, 'media_id' => $mediaId, 'position' => $position]);
            } elseif ((int) $held[$mediaId] !== $position) {
                $this->db->table('catalog.product_photos')->where('product_id', $productId)->where('media_id', $mediaId)->update(['position' => $position]);
            }
        }
    }

    public function withPhoto(string $mediaId): array
    {
        return array_values(array_map('strval', $this->db->table('catalog.product_photos')->where('media_id', strtolower($mediaId))->orderBy('product_id')->pluck('product_id')->all()));
    }

    public function removePhoto(string $productId, string $mediaId): void
    {
        $this->db->table('catalog.product_photos')->where('product_id', $productId)->where('media_id', strtolower($mediaId))->delete();
    }

    public function searchWords(string $productId): array
    {
        if (! Ulids::valid($productId)) {
            return [];
        }

        return array_values(array_map(
            static fn (stdClass $row): array => ['word' => (string) $row->word, 'normalized' => (string) $row->normalized],
            $this->db->table('catalog.product_search_words')->where('product_id', strtolower($productId))->orderBy('position')->get()->all(),
        ));
    }

    public function replaceSearchWords(string $productId, array $words): void
    {
        $this->db->table('catalog.product_search_words')->where('product_id', $productId)->delete();
        $rows = array_map(static fn (array $word, int $position): array => ['product_id' => $productId, 'normalized' => $word['normalized'], 'word' => $word['word'], 'position' => $position], $words, array_keys($words));

        if ($rows !== []) {
            $this->db->table('catalog.product_search_words')->insert($rows);
        }
    }

    public function filterValues(string $productId): array
    {
        if (! Ulids::valid($productId)) {
            return [];
        }

        $values = [];

        foreach ($this->db->table('catalog.product_filter_values')->where('product_id', strtolower($productId))->orderBy('attribute_id')->orderBy('value_id')->get() as $row) {
            $values[(string) $row->value_id] = (string) $row->attribute_id;
        }

        return $values;
    }

    public function replaceFilterValues(string $productId, array $values): void
    {
        $this->db->table('catalog.product_filter_values')->where('product_id', $productId)->delete();
        $rows = [];

        foreach ($values as $valueId => $attributeId) {
            $rows[] = ['product_id' => $productId, 'attribute_id' => $attributeId, 'value_id' => $valueId];
        }

        if ($rows !== []) {
            $this->db->table('catalog.product_filter_values')->insert($rows);
        }
    }

    public function anyWithFilterValue(string $valueId): bool
    {
        return Ulids::valid($valueId) && $this->db->table('catalog.product_filter_values')->where('value_id', strtolower($valueId))->exists();
    }

    public function anyWithFilterAttribute(string $attributeId): bool
    {
        return Ulids::valid($attributeId) && $this->db->table('catalog.product_filter_values')->where('attribute_id', strtolower($attributeId))->exists();
    }

    public function relations(string $productId, string $kind): array
    {
        if (! Ulids::valid($productId)) {
            return [];
        }

        return array_values(array_map('strval', $this->db->table('catalog.product_relations')->where('product_id', strtolower($productId))->where('kind', $kind)->orderBy('position')->pluck('related_id')->all()));
    }

    public function linkedFrom(string $productId): array
    {
        $linked = [];

        foreach ($this->db->table('catalog.product_relations')->where('related_id', strtolower($productId))->orderBy('product_id')->orderBy('kind')->get(['product_id', 'kind']) as $row) {
            $linked[(string) $row->product_id][] = (string) $row->kind;
        }

        return $linked;
    }

    public function replaceRelations(string $productId, string $kind, array $relatedIds): void
    {
        $this->db->table('catalog.product_relations')->where('product_id', $productId)->where('kind', $kind)->delete();
        $rows = array_map(static fn (string $relatedId, int $position): array => ['product_id' => $productId, 'related_id' => $relatedId, 'kind' => $kind, 'position' => $position], $relatedIds, array_keys($relatedIds));

        if ($rows !== []) {
            $this->db->table('catalog.product_relations')->insert($rows);
        }
    }

    /**
     * @return list<string>
     */
    private function ordered(string $table, string $owner, string $ownerId, string $column): array
    {
        if (! Ulids::valid($ownerId)) {
            return [];
        }

        return array_values(array_map('strval', $this->db->table($table)->where($owner, strtolower($ownerId))->orderBy('position')->pluck($column)->all()));
    }

    private function anyWith(string $column, string $id): bool
    {
        return Ulids::valid($id) && $this->db->table(self::TABLE)->where($column, strtolower($id))->exists();
    }

    public function idsInCategories(array $categoryIds): array
    {
        return $categoryIds === [] ? [] : $this->ids($this->db->table(self::TABLE)->whereIn('category_id', $categoryIds));
    }

    public function idsWithBrand(string $brandId): array
    {
        return Ulids::valid($brandId) ? $this->ids($this->db->table(self::TABLE)->where('brand_id', strtolower($brandId))) : [];
    }

    public function idsHiddenByCategoryIn(array $categoryIds): array
    {
        return $categoryIds === [] ? [] : $this->ids($this->db->table(self::TABLE)->whereIn('category_id', $categoryIds)->where('hidden_by_category', true));
    }

    public function idsHiddenByBrand(string $brandId): array
    {
        return Ulids::valid($brandId) ? $this->ids($this->db->table(self::TABLE)->where('brand_id', strtolower($brandId))->where('hidden_by_brand', true)) : [];
    }

    /**
     * @return list<string>
     */
    private function ids(Builder $query): array
    {
        return array_values(array_map('strval', $query->orderBy('id')->pluck('id')->all()));
    }

    private function read(string $productId, bool $lock): ?Product
    {
        if (! Ulids::valid($productId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($productId))->when($lock, fn ($query) => $query->lockForUpdate())->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        $slugs = $this->slugs->currentOf([(string) $row->id])[(string) $row->id] ?? ['ar' => '', 'en' => ''];

        return Product::reconstitute(
            (string) $row->id,
            ProductName::reconstitute((string) $row->name_ar, $row->name_en === null ? null : (string) $row->name_en),
            ProductSlugs::reconstitute($slugs['ar'], $slugs['en'] === '' ? null : $slugs['en']),
            $row->description_ar === null ? null : StructuredText::fromJson('description_ar', (string) $row->description_ar, Product::DESCRIPTION_MAX),
            $row->description_en === null ? null : StructuredText::fromJson('description_en', (string) $row->description_en, Product::DESCRIPTION_MAX),
            (string) $row->brand_id,
            $row->category_id === null ? null : (string) $row->category_id,
            $row->warranty_id === null ? null : (string) $row->warranty_id,
            $row->attribute_set_id === null ? null : (string) $row->attribute_set_id,
            ProductStage::from((string) $row->stage),
            $row->archived_from === null ? null : ProductStage::from((string) $row->archived_from),
            (bool) $row->hidden_by_category,
            (bool) $row->hidden_by_brand,
        );
    }

    /**
     * @return array<string, string|bool|null>
     */
    private static function toRow(Product $product): array
    {
        return [
            'name_ar' => $product->name()->ar,
            'name_en' => $product->name()->en,
            'description_ar' => $product->descriptionAr()?->toJson(),
            'description_en' => $product->descriptionEn()?->toJson(),
            'brand_id' => $product->brandId(),
            'category_id' => $product->categoryId(),
            'warranty_id' => $product->warrantyId(),
            'attribute_set_id' => $product->attributeSetId(),
            'stage' => $product->stage()->value,
            'archived_from' => $product->archivedFrom()?->value,
            'hidden_by_category' => $product->hiddenByCategory(),
            'hidden_by_brand' => $product->hiddenByBrand(),
        ];
    }
}
