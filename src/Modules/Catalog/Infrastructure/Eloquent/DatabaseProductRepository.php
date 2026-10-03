<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
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

    private function anyWith(string $column, string $id): bool
    {
        return Ulids::valid($id) && $this->db->table(self::TABLE)->where($column, strtolower($id))->exists();
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
        ];
    }
}
