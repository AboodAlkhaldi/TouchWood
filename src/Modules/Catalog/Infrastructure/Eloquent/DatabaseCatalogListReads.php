<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Catalog\Application\Query\Lists\AttributeRow;
use Modules\Catalog\Application\Query\Lists\BrandRow;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Lists\CategoryRow;
use Modules\Catalog\Application\Query\Lists\LabelRow;
use Modules\Catalog\Application\Query\Lists\NoResultSearchRow;
use Modules\Catalog\Application\Query\Lists\ReachedProductRow;
use Modules\Catalog\Application\Query\Lists\ValueRow;
use Modules\Catalog\Application\Query\Lists\WarrantyRow;
use Modules\Catalog\Application\Query\Lists\WordPairRow;
use stdClass;

/**
 * The shared lists as the panel's screens read them (catalog.md §4.4): rows over the tables, each
 * list in one query — what the screen also needs (a slug, a count, "is it used?") comes as a
 * sub-select of the same query, never one query per row. Reads lock nothing.
 */
final readonly class DatabaseCatalogListReads implements CatalogListReads
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function brands(): array
    {
        $rows = $this->db->table('catalog.brands as b')
            ->select([
                'b.id', 'b.number', 'b.name_ar', 'b.name_en', 'b.description_ar', 'b.description_en', 'b.logo_media_id',
                'b.origin_country', 'b.agency_type', 'b.is_default', 'b.show_in_default_listings', 'b.position', 'b.is_active',
            ])
            ->selectRaw("(SELECT s.slug FROM catalog.brand_slugs s WHERE s.brand_id = b.id AND s.locale = 'ar' AND s.is_current) as slug_ar")
            ->selectRaw("(SELECT s.slug FROM catalog.brand_slugs s WHERE s.brand_id = b.id AND s.locale = 'en' AND s.is_current) as slug_en")
            ->selectRaw('(SELECT count(*) FROM catalog.products p WHERE p.brand_id = b.id) as products')
            ->orderBy('b.position')->orderBy('b.name_en')->orderBy('b.id')
            ->get();

        return array_values($rows->map(static fn (stdClass $row): BrandRow => new BrandRow(
            (string) $row->id,
            (int) $row->number,
            (string) $row->name_ar,
            (string) $row->name_en,
            self::text($row->slug_ar),
            self::text($row->slug_en),
            (string) $row->agency_type,
            (bool) $row->show_in_default_listings,
            (bool) $row->is_default,
            (bool) $row->is_active,
            (int) $row->position,
            self::text($row->origin_country),
            self::text($row->logo_media_id),
            self::json($row->description_ar),
            self::json($row->description_en),
            (int) $row->products,
        ))->all());
    }

    public function categories(?string $storeId, ?string $baseStoreId): array
    {
        $rows = $this->db->table('catalog.categories as c')
            ->select(['c.id', 'c.parent_id', 'c.name_ar', 'c.name_en', 'c.is_active', 'c.deactivated_with_parent', 'c.image_media_id'])
            ->selectRaw("(SELECT s.slug FROM catalog.category_slugs s WHERE s.category_id = c.id AND s.locale = 'ar' AND s.is_current) as slug_ar")
            ->selectRaw("(SELECT s.slug FROM catalog.category_slugs s WHERE s.category_id = c.id AND s.locale = 'en' AND s.is_current) as slug_en")
            ->selectRaw('(SELECT count(*) FROM catalog.products p WHERE p.category_id = c.id) as products')
            ->selectRaw('(SELECT r.rank FROM catalog.store_category_ranks r WHERE r.category_id = c.id AND r.store_id = ?) as store_rank', [self::id($storeId)])
            ->selectRaw('(SELECT r.rank FROM catalog.store_category_ranks r WHERE r.category_id = c.id AND r.store_id = ?) as base_rank', [self::id($baseStoreId)])
            ->orderBy('c.name_en')->orderBy('c.id')
            ->get();

        return array_values($rows->map(static fn (stdClass $row): CategoryRow => new CategoryRow(
            (string) $row->id,
            self::text($row->parent_id),
            (string) $row->name_ar,
            (string) $row->name_en,
            self::text($row->slug_ar),
            self::text($row->slug_en),
            (bool) $row->is_active,
            (bool) $row->deactivated_with_parent,
            self::text($row->image_media_id),
            (int) $row->products,
            $row->store_rank === null ? null : (int) $row->store_rank,
            $row->base_rank === null ? null : (int) $row->base_rank,
        ))->all());
    }

    public function attributes(): array
    {
        return array_values(array_map(self::toAttribute(...), $this->attributeQuery()->get()->all()));
    }

    public function attribute(string $attributeId): ?AttributeRow
    {
        if (! Ulids::valid($attributeId)) {
            return null;
        }

        $row = $this->attributeQuery()->where('a.id', strtolower($attributeId))->first();

        return $row instanceof stdClass ? self::toAttribute($row) : null;
    }

    public function values(string $attributeId): array
    {
        if (! Ulids::valid($attributeId)) {
            return [];
        }

        $rows = $this->db->table('catalog.attribute_values as v')
            ->select(['v.id', 'v.name_ar', 'v.name_en', 'v.swatch', 'v.is_active', 'v.position'])
            ->selectRaw('(EXISTS (SELECT 1 FROM catalog.variant_values vv WHERE vv.value_id = v.id)
                OR EXISTS (SELECT 1 FROM catalog.product_filter_values f WHERE f.value_id = v.id)) as in_use')
            ->where('v.attribute_id', strtolower($attributeId))
            ->orderBy('v.position')->orderBy('v.name_en')->orderBy('v.id')
            ->get();

        return array_values($rows->map(static fn (stdClass $row): ValueRow => new ValueRow(
            (string) $row->id,
            (string) $row->name_ar,
            (string) $row->name_en,
            self::text($row->swatch),
            (bool) $row->is_active,
            (int) $row->position,
            (bool) $row->in_use,
        ))->all());
    }

    public function labels(): array
    {
        $rows = $this->db->table('catalog.labels as l')
            ->select(['l.id', 'l.name_ar', 'l.name_en', 'l.tone', 'l.is_active', 'l.position'])
            ->selectRaw('(SELECT count(DISTINCT x.product_id) FROM catalog.store_product_labels x WHERE x.label_id = l.id) as products')
            ->orderBy('l.position')->orderBy('l.name_en')->orderBy('l.id')
            ->get();

        return array_values($rows->map(static fn (stdClass $row): LabelRow => new LabelRow(
            (string) $row->id,
            (string) $row->name_ar,
            (string) $row->name_en,
            (string) $row->tone,
            (bool) $row->is_active,
            (int) $row->position,
            (int) $row->products,
        ))->all());
    }

    public function warranties(): array
    {
        $rows = $this->db->table('catalog.warranties as w')
            ->select(['w.id', 'w.name_ar', 'w.name_en', 'w.period_months', 'w.terms_ar', 'w.terms_en', 'w.is_active'])
            ->selectRaw('(SELECT count(*) FROM catalog.products p WHERE p.warranty_id = w.id) as products')
            ->orderBy('w.name_en')->orderBy('w.id')
            ->get();

        return array_values($rows->map(static fn (stdClass $row): WarrantyRow => new WarrantyRow(
            (string) $row->id,
            (string) $row->name_ar,
            (string) $row->name_en,
            $row->period_months === null ? null : (int) $row->period_months,
            self::json($row->terms_ar) ?? [],
            self::json($row->terms_en) ?? [],
            (bool) $row->is_active,
            (int) $row->products,
        ))->all());
    }

    public function wordPairs(): array
    {
        return array_values($this->db->table('catalog.word_pairs')->orderBy('word_a')->orderBy('word_b')->get(['id', 'word_a', 'word_b'])
            ->map(static fn (stdClass $row): WordPairRow => new WordPairRow((string) $row->id, (string) $row->word_a, (string) $row->word_b))
            ->all());
    }

    public function searchesWithNoResults(?string $storeId, string $since, int $page, int $perPage): array
    {
        $rows = $this->db->table('catalog.search_log')
            ->select(['query', 'store_id', 'locale'])
            ->selectRaw('count(*) as times, max(searched_at) as last_searched_at')
            ->where('results', 0)
            ->where('searched_at', '>=', $since)
            ->when($storeId !== null, fn ($query) => $query->where('store_id', strtolower((string) $storeId)))
            ->groupBy('query', 'store_id', 'locale')
            ->orderByDesc('times')->orderByDesc('last_searched_at')->orderBy('query')->orderBy('store_id')->orderBy('locale')
            // One more than a page, to know whether another follows, without counting every group.
            ->forPage($page, $perPage)->limit($perPage + 1)
            ->get()->all();

        $more = count($rows) > $perPage;

        return [array_map(static fn (stdClass $row): NoResultSearchRow => new NoResultSearchRow(
            (string) $row->query,
            (string) $row->store_id,
            (string) $row->locale,
            (int) $row->times,
            CarbonImmutable::parse((string) $row->last_searched_at)->toIso8601String(),
        ), array_slice($rows, 0, $perPage)), $more];
    }

    public function productsOfBrand(string $brandId): array
    {
        if (! Ulids::valid($brandId)) {
            return [];
        }

        return $this->reached($this->db->table('catalog.products as p')->where('p.brand_id', strtolower($brandId)));
    }

    public function productsUnderCategory(string $categoryId): array
    {
        if (! Ulids::valid($categoryId)) {
            return [];
        }

        $below = 'WITH RECURSIVE below(id) AS (
                SELECT id FROM catalog.categories WHERE id = ?
                UNION ALL
                SELECT c.id FROM catalog.categories c JOIN below ON c.parent_id = below.id
            ) SELECT id FROM below';

        return $this->reached($this->db->table('catalog.products as p')->whereRaw("p.category_id IN ({$below})", [strtolower($categoryId)]));
    }

    /**
     * @param  Builder  $query
     * @return list<ReachedProductRow>
     */
    private function reached($query): array
    {
        return array_values($query->orderBy('p.name_ar')->orderBy('p.id')->get(['p.id', 'p.name_ar', 'p.name_en', 'p.stage', 'p.category_id'])
            ->map(static fn (stdClass $row): ReachedProductRow => new ReachedProductRow(
                (string) $row->id,
                (string) $row->name_ar,
                self::text($row->name_en),
                (string) $row->stage,
                self::text($row->category_id),
            ))->all());
    }

    /**
     * An attribute's row: its job locked by values or by details variants carry (amendments 1(i),
     * 3(k)); held by products making their variants of it (amendment 16(b)); used by a product's
     * variants or its filters.
     *
     * @return Builder
     */
    private function attributeQuery()
    {
        return $this->db->table('catalog.attributes as a')
            ->select(['a.id', 'a.name_ar', 'a.name_en', 'a.kind', 'a.unit_ar', 'a.unit_en', 'a.is_colour', 'a.is_active', 'a.position'])
            ->selectRaw('(SELECT count(*) FROM catalog.attribute_values v WHERE v.attribute_id = a.id) as values_count')
            ->selectRaw('EXISTS (SELECT 1 FROM catalog.variant_details d WHERE d.attribute_id = a.id) as has_details')
            ->selectRaw('EXISTS (SELECT 1 FROM catalog.product_attributes pa WHERE pa.attribute_id = a.id) as in_products')
            ->selectRaw('(EXISTS (SELECT 1 FROM catalog.variant_values vv WHERE vv.attribute_id = a.id)
                OR EXISTS (SELECT 1 FROM catalog.product_filter_values f WHERE f.attribute_id = a.id)) as carried')
            ->orderBy('a.position')->orderBy('a.name_en')->orderBy('a.id');
    }

    private static function toAttribute(stdClass $row): AttributeRow
    {
        $values = (int) $row->values_count;

        return new AttributeRow(
            (string) $row->id,
            (string) $row->name_ar,
            (string) $row->name_en,
            (string) $row->kind,
            self::text($row->unit_ar),
            self::text($row->unit_en),
            (bool) $row->is_colour,
            (bool) $row->is_active,
            (int) $row->position,
            $values,
            $values > 0 || (bool) $row->has_details,
            (bool) $row->in_products,
            (bool) $row->in_products || (bool) $row->has_details || (bool) $row->carried,
        );
    }

    /** A store id as a query's binding: a malformed one matches no row. */
    private static function id(?string $storeId): string
    {
        return $storeId === null ? '' : strtolower(trim($storeId));
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
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
}
