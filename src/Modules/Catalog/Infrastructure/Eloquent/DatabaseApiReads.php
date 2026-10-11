<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Application\Api\ApiReads;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Dto\VariantValueDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * `ApiReads` in SQL: a variant list in two queries (its rows, then its values with their names), a
 * product list in one — whatever the number of ids.
 */
final readonly class DatabaseApiReads implements ApiReads
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function variantIdsOf(string $productId, bool $includeArchived): array
    {
        if (! Ulids::valid(trim($productId))) {
            return [];
        }

        return array_values(array_map('strval', $this->db->table('catalog.variants')
            ->where('product_id', strtolower(trim($productId)))
            ->when(! $includeArchived, fn ($query) => $query->where('is_archived', false))
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->all()));
    }

    public function switchedOnVariantIds(string $storeId): array
    {
        return array_values(array_map('strval', $this->db->table('catalog.store_variants')
            ->where('store_id', strtolower($storeId))
            ->where('is_active', true)
            ->orderBy('variant_id')
            ->pluck('variant_id')
            ->all()));
    }

    public function variants(array $variantIds): array
    {
        $ids = self::ids($variantIds);

        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('catalog.variants')->whereIn('id', $ids)->get()->all();
        $values = [];

        foreach ($this->db->select(
            'SELECT vv.variant_id, vv.attribute_id, vv.value_id, a.name_ar AS attribute_ar, a.name_en AS attribute_en, av.name_ar AS value_ar, av.name_en AS value_en'
            .' FROM catalog.variant_values vv'
            .' JOIN catalog.variants v ON v.id = vv.variant_id'
            .' JOIN catalog.attributes a ON a.id = vv.attribute_id'
            .' JOIN catalog.attribute_values av ON av.id = vv.value_id'
            .' LEFT JOIN catalog.product_attributes pa ON pa.product_id = v.product_id AND pa.attribute_id = vv.attribute_id'
            .' WHERE vv.variant_id IN ('.implode(',', array_fill(0, count($ids), '?')).')'
            .' ORDER BY vv.variant_id, pa.position, vv.attribute_id',
            $ids,
        ) as $value) {
            $values[(string) $value->variant_id][(string) $value->value_id] = new VariantValueDto(
                (string) $value->attribute_id,
                new TranslatedTextDto((string) $value->attribute_ar, (string) $value->attribute_en),
                (string) $value->value_id,
                new TranslatedTextDto((string) $value->value_ar, (string) $value->value_en),
            );
        }

        $found = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            // In the product's own order of its variant attributes (amendment 16(b)), as read.
            $ordered = array_values($values[$id] ?? []);

            $found[$id] = new VariantDto(
                $id,
                (string) $row->product_id,
                (string) $row->code,
                $ordered,
                $row->weight_grams === null ? null : (int) $row->weight_grams,
                $row->length_mm === null ? null : (int) $row->length_mm,
                $row->width_mm === null ? null : (int) $row->width_mm,
                $row->height_mm === null ? null : (int) $row->height_mm,
                (bool) $row->is_archived,
            );
        }

        return $found;
    }

    public function products(array $productIds): array
    {
        $ids = self::ids($productIds);

        if ($ids === []) {
            return [];
        }

        $found = [];

        foreach ($this->db->table('catalog.products')->whereIn('id', $ids)->get(['id', 'name_ar', 'name_en', 'stage', 'brand_id', 'category_id', 'warranty_id']) as $row) {
            $found[(string) $row->id] = new ProductDto(
                (string) $row->id,
                (string) $row->name_ar,
                $row->name_en === null ? null : (string) $row->name_en,
                ProductStage::from((string) $row->stage),
                (string) $row->brand_id,
                $row->category_id === null ? null : (string) $row->category_id,
                $row->warranty_id === null ? null : (string) $row->warranty_id,
            );
        }

        return $found;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string> each once, lower case, only the ids that are ids
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => strtolower(trim($id)), $ids),
            Ulids::valid(...),
        )));
    }
}
