<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Application\Api\ApiReads;
use Modules\Catalog\Public\Dto\BrandDto;
use Modules\Catalog\Public\Dto\LineFactsDto;
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

    public function variantIdsOfProducts(array $productIds, bool $includeArchived): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => strtolower(trim($id)), $productIds),
            static fn (string $id): bool => Ulids::valid($id),
        )));

        if ($ids === []) {
            return [];
        }

        $found = [];

        // One bound array, never a parameter an id: a thousand products cost what one does.
        foreach ($this->db->table('catalog.variants')
            ->whereRaw('product_id = ANY(?::text[])', ['{'.implode(',', $ids).'}'])
            ->when(! $includeArchived, fn ($query) => $query->where('is_archived', false))
            ->orderBy('product_id')->orderBy('position')->orderBy('id')
            ->get(['id', 'product_id']) as $row) {
            $found[(string) $row->product_id][] = (string) $row->id;
        }

        return $found;
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

    public function variantIdsByCodes(array $codes): array
    {
        $ids = [];

        // Under PostgreSQL's bind-parameter limit, however many codes a read names.
        foreach (array_chunk(array_values(array_unique($codes)), 1000) as $chunk) {
            foreach ($this->db->table('catalog.variants')->whereIn('code', $chunk)->get(['id', 'code']) as $row) {
                $ids[(string) $row->code] = (string) $row->id;
            }
        }

        return $ids;
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

    public function brands(array $brandIds): array
    {
        $ids = self::ids($brandIds);

        if ($ids === []) {
            return [];
        }

        $found = [];

        foreach ($this->db->table('catalog.brands')->whereIn('id', $ids)->get(['id', 'name_ar', 'name_en', 'is_active']) as $row) {
            $found[(string) $row->id] = new BrandDto((string) $row->id, (string) $row->name_ar, (string) $row->name_en, (bool) $row->is_active);
        }

        return $found;
    }

    public function productsSoldIn(string $storeId, array $productIds): array
    {
        $ids = self::ids($productIds);

        if ($ids === []) {
            return [];
        }

        return array_values(array_map(strval(...), $this->db->table('catalog.products as p')
            ->join('catalog.store_products as sp', fn ($join) => $join->on('sp.product_id', '=', 'p.id')->where('sp.store_id', '=', strtolower($storeId)))
            ->whereIn('p.id', $ids)
            ->where('p.stage', 'READY')
            ->where('p.hidden_by_category', false)
            ->where('p.hidden_by_brand', false)
            ->where('sp.not_available_now', false)
            ->whereExists(fn ($variants) => $variants->selectRaw('1')
                ->from('catalog.store_variants as sv')
                ->join('catalog.variants as v', 'v.id', '=', 'sv.variant_id')
                ->whereColumn('sv.store_id', 'sp.store_id')
                ->whereColumn('sv.product_id', 'p.id')
                ->where('sv.is_active', true)
                ->where('sv.not_available_now', false)
                ->where('v.is_archived', false))
            ->pluck('p.id')
            ->all()));
    }

    public function lineFacts(array $variantIds): array
    {
        $ids = self::ids($variantIds);

        if ($ids === []) {
            return [];
        }

        // One query whatever the cart: each variant's product and brand, and its product's category
        // walked up to the top. The depth bound only guards the walk; Catalog keeps the tree acyclic.
        $rows = $this->db->select(
            'WITH RECURSIVE lines AS ('
            .' SELECT v.id AS variant_id, p.id AS product_id, p.brand_id, p.category_id'
            .' FROM catalog.variants v JOIN catalog.products p ON p.id = v.product_id'
            .' WHERE v.id IN ('.implode(',', array_fill(0, count($ids), '?')).')'
            .'), up AS ('
            .' SELECT variant_id, category_id, 0 AS depth FROM lines WHERE category_id IS NOT NULL'
            .' UNION ALL'
            .' SELECT up.variant_id, c.parent_id, up.depth + 1 FROM up JOIN catalog.categories c ON c.id = up.category_id'
            .' WHERE c.parent_id IS NOT NULL AND up.depth < 100'
            .') SELECT l.variant_id, l.product_id, l.brand_id, up.category_id'
            .' FROM lines l LEFT JOIN up ON up.variant_id = l.variant_id'
            .' ORDER BY l.variant_id, up.depth',
            $ids,
        );

        $facts = [];
        $categories = [];

        foreach ($rows as $row) {
            $variantId = (string) $row->variant_id;
            $facts[$variantId] ??= [(string) $row->product_id, (string) $row->brand_id];
            $categories[$variantId] ??= [];

            if ($row->category_id !== null) {
                $categories[$variantId][] = (string) $row->category_id;
            }
        }

        $found = [];

        foreach ($facts as $variantId => [$productId, $brandId]) {
            $found[$variantId] = new LineFactsDto($variantId, $productId, $brandId, $categories[$variantId]);
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
