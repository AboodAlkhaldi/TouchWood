<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Application\Query\Products\AttributeChoice;
use Modules\Catalog\Application\Query\Products\CatalogProductReads;
use Modules\Catalog\Application\Query\Products\ProductCore;
use Modules\Catalog\Application\Query\Products\ProductFilter;
use Modules\Catalog\Application\Query\Products\ProductOptions;
use Modules\Catalog\Application\Query\Products\ProductRow;
use Modules\Catalog\Application\Query\Products\RelatedRow;
use Modules\Catalog\Application\Query\Products\VariantView;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * The products as the panel's screens read them (catalog.md §4.4 S8, S9): each read one query, what
 * a row also shows - its codes, its first photo, where it is on - a sub-select of that query, never one
 * query per row (frontend.md §5). Reads lock nothing.
 *
 * Products come newest first by their ids: a ULID begins with the moment it was made, so its byte
 * order is its age - compared `COLLATE "C"`, the database's language order being another (lesson 132).
 */
final readonly class DatabaseCatalogProductReads implements CatalogProductReads
{
    /**
     * A product's codes as its variants carry them now, archived ones included. A code it gave up
     * stays its own (`product_codes`, amendment 3(e)) and still finds it, but is no longer shown as
     * one of its codes.
     */
    private const string CODES = "(SELECT coalesce(json_agg(DISTINCT v.code ORDER BY v.code), '[]') FROM catalog.variants v WHERE v.product_id = p.id) as codes";

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function products(ProductFilter $filter, int $perPage): array
    {
        $store = $filter->storeId ?? '';
        $query = $this->db->table('catalog.products as p')
            ->join('catalog.brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('catalog.categories as k', 'k.id', '=', 'p.category_id')
            ->leftJoin('catalog.store_products as sp', function ($join) use ($store): void {
                $join->on('sp.product_id', '=', 'p.id')->where('sp.store_id', '=', $store);
            })
            ->select([
                'p.id', 'p.name_ar', 'p.name_en', 'p.stage', 'p.brand_id', 'b.name_ar as brand_ar', 'b.name_en as brand_en',
                'p.category_id', 'k.name_ar as category_ar', 'k.name_en as category_en', 'sp.product_id as chosen', 'sp.not_available_now',
            ])
            ->selectRaw(self::CODES)
            ->selectRaw('(SELECT ph.media_id FROM catalog.product_photos ph WHERE ph.product_id = p.id ORDER BY ph.position LIMIT 1) as photo')
            ->selectRaw('(SELECT count(*) FROM catalog.variants v WHERE v.product_id = p.id AND NOT v.is_archived) as variants')
            ->selectRaw("(SELECT coalesce(json_agg(DISTINCT sv.store_id), '[]') FROM catalog.store_variants sv WHERE sv.product_id = p.id AND sv.is_active) as on_in")
            ->selectRaw('(SELECT count(*) FROM catalog.store_variants sv WHERE sv.product_id = p.id AND sv.store_id = ? AND sv.is_active) as on_here', [$store])
            ->orderByRaw('p.id COLLATE "C" DESC')
            ->limit($perPage + 1);

        if ($filter->search !== null) {
            $like = '%'.addcslashes($filter->search, '\\%_').'%';
            $query->where(function ($where) use ($filter, $like): void {
                $where->where('p.name_ar', 'ilike', $like)->orWhere('p.name_en', 'ilike', $like);

                // A code typed finds the product holding it (S8).
                if (preg_match('/\A[0-9]{1,10}\z/', (string) $filter->search) === 1) {
                    $where->orWhereExists(fn ($codes) => $codes->selectRaw('1')->from('catalog.product_codes as c')->whereColumn('c.product_id', 'p.id')->where('c.code', $filter->search));
                }
            });
        }

        if ($filter->stage !== null) {
            $query->where('p.stage', $filter->stage);
        }

        if ($filter->categoryId !== null) {
            $query->where('p.category_id', $filter->categoryId);
        }

        if ($filter->brandId !== null) {
            $query->where('p.brand_id', $filter->brandId);
        }

        if ($filter->after !== null) {
            $query->whereRaw('p.id COLLATE "C" < ?', [$filter->after]);
        }

        $active = 'EXISTS (SELECT 1 FROM catalog.store_variants sv WHERE sv.product_id = p.id AND sv.store_id = ? AND sv.is_active)';

        match ($filter->storeId === null ? null : $filter->storeState) {
            ProductFilter::NOT_CHOSEN => $query->whereNull('sp.product_id'),
            ProductFilter::NOT_AVAILABLE => $query->where('sp.not_available_now', true),
            ProductFilter::ON => $query->whereNotNull('sp.product_id')->where('sp.not_available_now', false)->whereRaw($active, [$store]),
            ProductFilter::OFF => $query->whereNotNull('sp.product_id')->where('sp.not_available_now', false)->whereRaw('NOT '.$active, [$store]),
            default => null,
        };

        $rows = $query->get()->all();
        $more = count($rows) > $perPage;

        return [array_map(fn (stdClass $row): ProductRow => new ProductRow(
            (string) $row->id,
            (string) $row->name_ar,
            self::text($row->name_en),
            self::strings($row->codes),
            (string) $row->stage,
            (string) $row->brand_id,
            (string) $row->brand_ar,
            (string) $row->brand_en,
            self::text($row->category_id),
            self::text($row->category_ar),
            self::text($row->category_en),
            self::text($row->photo),
            (int) $row->variants,
            self::strings($row->on_in),
            $filter->storeId === null ? null : self::state($row),
            (int) $row->on_here,
        ), array_slice($rows, 0, $perPage)), $more];
    }

    public function core(string $productId): ?ProductCore
    {
        if (! Ulids::valid($productId)) {
            return null;
        }

        $row = $this->db->table('catalog.products as p')
            ->join('catalog.brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('catalog.categories as k', 'k.id', '=', 'p.category_id')
            ->leftJoin('catalog.attribute_sets as s', 's.id', '=', 'p.attribute_set_id')
            ->where('p.id', strtolower($productId))
            ->select([
                'p.id', 'p.name_ar', 'p.name_en', 'p.stage', 'p.archived_from', 'p.brand_id', 'b.name_ar as brand_ar', 'b.name_en as brand_en',
                'p.category_id', 'p.warranty_id', 'p.attribute_set_id', 's.name_ar as set_ar', 's.name_en as set_en',
                'p.description_ar', 'p.description_en', 'p.hidden_by_category', 'p.hidden_by_brand',
            ])
            ->selectRaw("(SELECT sl.slug FROM catalog.product_slugs sl WHERE sl.product_id = p.id AND sl.locale = 'ar' AND sl.is_current) as slug_ar")
            ->selectRaw("(SELECT sl.slug FROM catalog.product_slugs sl WHERE sl.product_id = p.id AND sl.locale = 'en' AND sl.is_current) as slug_en")
            ->selectRaw(self::CODES)
            ->selectRaw("(SELECT coalesce(json_agg(m.attribute_id ORDER BY m.position), '[]') FROM catalog.attribute_set_members m WHERE m.attribute_set_id = p.attribute_set_id) as set_attributes")
            ->selectRaw("(SELECT coalesce(json_agg(ph.media_id ORDER BY ph.position), '[]') FROM catalog.product_photos ph WHERE ph.product_id = p.id) as gallery")
            ->selectRaw("(SELECT coalesce(json_agg(DISTINCT sv.store_id), '[]') FROM catalog.store_variants sv WHERE sv.product_id = p.id AND sv.is_active) as on_in")
            ->selectRaw('EXISTS (SELECT 1 FROM catalog.variants v WHERE v.product_id = p.id AND NOT v.is_archived) as has_variant')
            // As Readiness asks: an active category with no sub-category.
            ->selectRaw('coalesce(k.is_active AND NOT EXISTS (SELECT 1 FROM catalog.categories c2 WHERE c2.parent_id = k.id), false) as category_showable')
            ->selectRaw(<<<'SQL'
                (WITH RECURSIVE up AS (
                    SELECT c.id, c.parent_id, c.name_ar, c.name_en, 0 AS depth FROM catalog.categories c WHERE c.id = p.category_id
                    UNION ALL
                    SELECT c.id, c.parent_id, c.name_ar, c.name_en, up.depth + 1 FROM catalog.categories c JOIN up ON c.id = up.parent_id
                ) SELECT coalesce(json_agg(json_build_object('ar', up.name_ar, 'en', up.name_en) ORDER BY up.depth DESC), '[]') FROM up) as category_path
                SQL)
            ->selectRaw(<<<'SQL'
                json_build_object(
                    'variants', (SELECT count(*) FROM catalog.variants v WHERE v.product_id = p.id),
                    'photos', (SELECT count(*) FROM catalog.product_photos ph WHERE ph.product_id = p.id),
                    'searchWords', (SELECT count(*) FROM catalog.product_search_words w WHERE w.product_id = p.id),
                    'filterValues', (SELECT count(*) FROM catalog.product_filter_values f WHERE f.product_id = p.id),
                    'related', (SELECT count(*) FROM catalog.product_relations r WHERE r.product_id = p.id AND r.kind = 'RELATED'),
                    'goesWith', (SELECT count(*) FROM catalog.product_relations r WHERE r.product_id = p.id AND r.kind = 'GOES_WITH')
                ) as counts
                SQL)
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var array{variants: int, photos: int, searchWords: int, filterValues: int, related: int, goesWith: int} $counts */
        $counts = array_map('intval', self::json($row->counts) ?? []) + ['variants' => 0, 'photos' => 0, 'searchWords' => 0, 'filterValues' => 0, 'related' => 0, 'goesWith' => 0];

        return new ProductCore(
            (string) $row->id,
            (string) $row->name_ar,
            self::text($row->name_en),
            self::text($row->slug_ar),
            self::text($row->slug_en),
            (string) $row->stage,
            self::text($row->archived_from),
            (string) $row->brand_id,
            (string) $row->brand_ar,
            (string) $row->brand_en,
            self::text($row->category_id),
            array_map(static fn (array $step): array => ['ar' => (string) ($step['ar'] ?? ''), 'en' => (string) ($step['en'] ?? '')], self::lists($row->category_path)),
            (bool) $row->category_showable,
            self::text($row->warranty_id),
            self::text($row->attribute_set_id),
            self::text($row->set_ar),
            self::text($row->set_en),
            self::strings($row->set_attributes),
            self::json($row->description_ar),
            self::json($row->description_en),
            (bool) $row->hidden_by_category,
            (bool) $row->hidden_by_brand,
            self::strings($row->codes),
            self::strings($row->gallery),
            self::strings($row->on_in),
            (bool) $row->has_variant,
            $counts,
        );
    }

    public function variants(string $productId): array
    {
        if (! Ulids::valid($productId)) {
            return [];
        }

        $rows = $this->db->table('catalog.variants as v')
            ->where('v.product_id', strtolower($productId))
            ->select(['v.id', 'v.code', 'v.position', 'v.is_archived', 'v.weight_grams', 'v.length_mm', 'v.width_mm', 'v.height_mm'])
            ->selectRaw(<<<'SQL'
                (SELECT coalesce(json_agg(json_build_object('attributeId', vv.attribute_id, 'valueId', vv.value_id, 'nameAr', av.name_ar, 'nameEn', av.name_en, 'swatch', av.swatch)
                    ORDER BY a.position, a.name_en), '[]')
                    FROM catalog.variant_values vv JOIN catalog.attribute_values av ON av.id = vv.value_id JOIN catalog.attributes a ON a.id = vv.attribute_id
                    WHERE vv.variant_id = v.id) as vals
                SQL)
            ->selectRaw(<<<'SQL'
                (SELECT coalesce(json_agg(json_build_object('attributeId', d.attribute_id, 'textAr', d.text_ar, 'textEn', d.text_en, 'number', d.number::text)
                    ORDER BY a.position, a.name_en), '[]')
                    FROM catalog.variant_details d JOIN catalog.attributes a ON a.id = d.attribute_id
                    WHERE d.variant_id = v.id) as details
                SQL)
            ->selectRaw("(SELECT coalesce(json_agg(vp.media_id ORDER BY vp.position), '[]') FROM catalog.variant_photos vp WHERE vp.variant_id = v.id) as photos")
            ->orderBy('v.position')->orderBy('v.code')->orderBy('v.id')
            ->get();

        return array_values($rows->map(static fn (stdClass $row): VariantView => new VariantView(
            (string) $row->id,
            (string) $row->code,
            (int) $row->position,
            (bool) $row->is_archived,
            array_map(static fn (array $value): array => [
                'attributeId' => (string) $value['attributeId'],
                'valueId' => (string) $value['valueId'],
                'nameAr' => (string) $value['nameAr'],
                'nameEn' => (string) $value['nameEn'],
                'swatch' => isset($value['swatch']) ? (string) $value['swatch'] : null,
            ], self::lists($row->vals)),
            array_map(static fn (array $detail): array => [
                'attributeId' => (string) $detail['attributeId'],
                'textAr' => isset($detail['textAr']) ? (string) $detail['textAr'] : null,
                'textEn' => isset($detail['textEn']) ? (string) $detail['textEn'] : null,
                'number' => isset($detail['number']) ? (string) $detail['number'] : null,
            ], self::lists($row->details)),
            self::number($row->weight_grams),
            self::number($row->length_mm),
            self::number($row->width_mm),
            self::number($row->height_mm),
            self::strings($row->photos),
        ))->all());
    }

    public function attributeChoices(): array
    {
        $rows = $this->db->table('catalog.attributes as a')
            ->select(['a.id', 'a.name_ar', 'a.name_en', 'a.kind', 'a.unit_ar', 'a.unit_en', 'a.is_colour', 'a.is_active'])
            ->selectRaw(<<<'SQL'
                (SELECT coalesce(json_agg(json_build_object('id', v.id, 'nameAr', v.name_ar, 'nameEn', v.name_en, 'swatch', v.swatch, 'active', v.is_active)
                    ORDER BY v.position, v.name_en, v.id), '[]')
                    FROM catalog.attribute_values v WHERE v.attribute_id = a.id) as vals
                SQL)
            ->orderBy('a.position')->orderBy('a.name_en')->orderBy('a.id')
            ->get();

        return array_values($rows->map(static fn (stdClass $row): AttributeChoice => new AttributeChoice(
            (string) $row->id,
            (string) $row->name_ar,
            (string) $row->name_en,
            (string) $row->kind,
            self::text($row->unit_ar),
            self::text($row->unit_en),
            (bool) $row->is_colour,
            (bool) $row->is_active,
            array_map(static fn (array $value): array => [
                'id' => (string) $value['id'],
                'nameAr' => (string) $value['nameAr'],
                'nameEn' => (string) $value['nameEn'],
                'swatch' => isset($value['swatch']) ? (string) $value['swatch'] : null,
                'active' => (bool) ($value['active'] ?? false),
            ], self::lists($row->vals)),
        ))->all());
    }

    public function options(): ProductOptions
    {
        $row = $this->db->selectOne(<<<'SQL'
            SELECT
                (SELECT coalesce(json_agg(json_build_object('id', b.id, 'nameAr', b.name_ar, 'nameEn', b.name_en, 'isDefault', b.is_default, 'active', b.is_active)
                    ORDER BY b.position, b.name_en, b.id), '[]') FROM catalog.brands b) as brands,
                (SELECT coalesce(json_agg(json_build_object('id', c.id, 'parentId', c.parent_id, 'ar', c.name_ar, 'en', c.name_en, 'active', c.is_active)
                    ORDER BY c.name_en, c.id), '[]') FROM catalog.categories c) as categories,
                (SELECT coalesce(json_agg(json_build_object('id', w.id, 'nameAr', w.name_ar, 'nameEn', w.name_en, 'periodMonths', w.period_months, 'active', w.is_active)
                    ORDER BY w.name_en, w.id), '[]') FROM catalog.warranties w) as warranties,
                (SELECT coalesce(json_agg(json_build_object('id', s.id, 'nameAr', s.name_ar, 'nameEn', s.name_en,
                    'attributeIds', (SELECT coalesce(json_agg(m.attribute_id ORDER BY m.position), '[]') FROM catalog.attribute_set_members m WHERE m.attribute_set_id = s.id))
                    ORDER BY s.name_en, s.id), '[]') FROM catalog.attribute_sets s WHERE s.is_active) as variations
            SQL);

        return new ProductOptions(
            array_map(static fn (array $brand): array => [
                'id' => (string) $brand['id'],
                'nameAr' => (string) $brand['nameAr'],
                'nameEn' => (string) $brand['nameEn'],
                'isDefault' => (bool) ($brand['isDefault'] ?? false),
                'active' => (bool) ($brand['active'] ?? false),
            ], self::lists($row?->brands)),
            self::lowestCategories(self::lists($row?->categories)),
            array_map(static fn (array $warranty): array => [
                'id' => (string) $warranty['id'],
                'nameAr' => (string) $warranty['nameAr'],
                'nameEn' => (string) $warranty['nameEn'],
                'periodMonths' => isset($warranty['periodMonths']) ? (int) $warranty['periodMonths'] : null,
                'active' => (bool) ($warranty['active'] ?? false),
            ], self::lists($row?->warranties)),
            array_map(static fn (array $set): array => [
                'id' => (string) $set['id'],
                'nameAr' => (string) $set['nameAr'],
                'nameEn' => (string) $set['nameEn'],
                'attributeIds' => array_values(array_map('strval', is_array($set['attributeIds'] ?? null) ? $set['attributeIds'] : [])),
            ], self::lists($row?->variations)),
        );
    }

    public function searchAndFilters(string $productId): array
    {
        if (! Ulids::valid($productId)) {
            return ['words' => [], 'valueIds' => []];
        }

        $id = strtolower($productId);
        $row = $this->db->selectOne(<<<'SQL'
            SELECT
                (SELECT coalesce(json_agg(w.word ORDER BY w.position), '[]') FROM catalog.product_search_words w WHERE w.product_id = ?) as words,
                (SELECT coalesce(json_agg(f.value_id ORDER BY f.value_id), '[]') FROM catalog.product_filter_values f WHERE f.product_id = ?) as vals
            SQL, [$id, $id]);

        return ['words' => self::strings($row?->words), 'valueIds' => self::strings($row?->vals)];
    }

    public function related(string $productId): array
    {
        if (! Ulids::valid($productId)) {
            return [];
        }

        $rows = $this->db->table('catalog.product_relations as r')
            ->join('catalog.products as p', 'p.id', '=', 'r.related_id')
            ->where('r.product_id', strtolower($productId))
            ->select(['r.kind', 'r.related_id', 'p.name_ar', 'p.name_en', 'p.stage'])
            ->selectRaw(self::CODES)
            ->orderByRaw("CASE r.kind WHEN 'RELATED' THEN 0 ELSE 1 END")
            ->orderBy('r.position')
            ->get();

        return array_values($rows->map(static fn (stdClass $row): RelatedRow => new RelatedRow(
            (string) $row->kind,
            (string) $row->related_id,
            (string) $row->name_ar,
            self::text($row->name_en),
            self::strings($row->codes),
            (string) $row->stage,
        ))->all());
    }

    /**
     * The categories that hold products - those with no sub-category of any state (a category holding
     * products takes none, amendment 3(i)) - each with its path from the top and whether it is active,
     * as Readiness asks a product's to be.
     *
     * @param  list<array<string, mixed>>  $categories
     * @return list<array{id: string, path: list<array{ar: string, en: string}>, active: bool}>
     */
    private static function lowestCategories(array $categories): array
    {
        $byId = [];
        $parents = [];

        foreach ($categories as $category) {
            $byId[(string) $category['id']] = $category;

            if (isset($category['parentId'])) {
                $parents[(string) $category['parentId']] = true;
            }
        }

        $lowest = [];

        foreach ($byId as $id => $category) {
            if (isset($parents[$id])) {
                continue;
            }

            $path = [];
            $at = $category;

            // A tree has no loop (CategoryLoop); the bound only stops a broken row being followed for ever.
            for ($depth = 0; $at !== null && $depth < 64; $depth++) {
                array_unshift($path, ['ar' => (string) $at['ar'], 'en' => (string) $at['en']]);
                $at = isset($at['parentId']) ? ($byId[(string) $at['parentId']] ?? null) : null;
            }

            $lowest[] = ['id' => $id, 'path' => $path, 'active' => (bool) ($category['active'] ?? false)];
        }

        return $lowest;
    }

    /** With a store chosen: the product's state there (S8). */
    private static function state(stdClass $row): string
    {
        return match (true) {
            $row->chosen === null => ProductFilter::NOT_CHOSEN,
            (bool) $row->not_available_now => ProductFilter::NOT_AVAILABLE,
            (int) $row->on_here > 0 => ProductFilter::ON,
            default => ProductFilter::OFF,
        };
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function number(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function json(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * A JSON array of objects.
     *
     * @return list<array<string, mixed>>
     */
    private static function lists(mixed $value): array
    {
        return array_values(array_filter(self::json($value) ?? [], 'is_array'));
    }

    /**
     * A JSON array of texts.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_map('strval', array_filter(self::json($value) ?? [], 'is_scalar')));
    }
}
