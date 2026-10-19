<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Catalog\Application\Query\Shop\CardLabel;
use Modules\Catalog\Application\Query\Shop\CardPage;
use Modules\Catalog\Application\Query\Shop\CardRating;
use Modules\Catalog\Application\Query\Shop\CategoryFilters;
use Modules\Catalog\Application\Query\Shop\Cursor;
use Modules\Catalog\Application\Query\Shop\FilterChoice;
use Modules\Catalog\Application\Query\Shop\FilterGroup;
use Modules\Catalog\Application\Query\Shop\ProductCard;
use Modules\Catalog\Application\Query\Shop\SearchCursor;
use Modules\Catalog\Application\Query\Shop\SearchResults;
use Modules\Catalog\Application\Query\Shop\SellingTerms;
use Modules\Catalog\Application\Query\Shop\ShopChoice;
use Modules\Catalog\Application\Query\Shop\ShopChoiceValue;
use Modules\Catalog\Application\Query\Shop\ShopFilters;
use Modules\Catalog\Application\Query\Shop\ShopLink;
use Modules\Catalog\Application\Query\Shop\ShopReader;
use Modules\Catalog\Application\Query\Shop\ShopVariant;
use Modules\Catalog\Application\Query\Shop\ShopWarranty;
use Modules\Catalog\Application\Query\Shop\VariantChoice;
use Modules\Catalog\Application\Query\Shop\VariantDetail;
use Modules\Catalog\Application\Search\SearchTerms;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * The shopper's reads, from the listing (catalog.md §5.4): a grid is one indexed query on it, its
 * cards' labels read in the same query. Ids from a request that are not ULIDs find nothing without
 * asking the database. Names come in the page's language — `ar` or `en`, nothing else is ever
 * written into a column name here. Who is looking narrows every read in the same query, from the
 * row's own columns (amendment 14): it costs no query of its own.
 */
final readonly class DatabaseShopReader implements ShopReader
{
    private const string LISTING = 'catalog.listing';

    /** @var array<string, array{string, string}> table and owner column, by kind */
    private const array SLUGS = [
        'product' => ['catalog.product_slugs', 'product_id'],
        'category' => ['catalog.category_slugs', 'category_id'],
        'brand' => ['catalog.brand_slugs', 'brand_id'],
    ];

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function menu(string $storeId, ?string $baseStoreId, string $locale, ShopperKind $viewer): array
    {
        $name = self::column('name', $locale);
        $bindings = [$storeId, $locale, $locale, $storeId, $baseStoreId];

        $rows = $this->db->select(
            'WITH listed AS (SELECT DISTINCT unnest(category_path) AS id FROM '.self::LISTING
            .' WHERE store_id = ? AND locale = ? AND in_category_pages AND orderable AND '.self::seenBy($viewer).')'
            ." SELECT c.id, c.parent_id, c.{$name} AS name, s.slug, c.image_media_id FROM catalog.categories c"
            .' JOIN listed l ON l.id = c.id'
            .' JOIN catalog.category_slugs s ON s.category_id = c.id AND s.locale = ? AND s.is_current'
            .' LEFT JOIN catalog.store_category_ranks r ON r.store_id = ? AND r.category_id = c.id'
            .' LEFT JOIN catalog.store_category_ranks b ON b.store_id = ? AND b.category_id = c.id'
            ." WHERE c.is_active ORDER BY COALESCE(r.rank, b.rank) NULLS LAST, c.{$name}, c.id",
            $bindings,
        );

        return array_values(array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'parent_id' => $row->parent_id === null ? null : (string) $row->parent_id,
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'image_media_id' => $row->image_media_id === null ? null : (string) $row->image_media_id,
        ], $rows));
    }

    public function slugOwner(string $kind, string $locale, string $slug): ?array
    {
        [$table, $owner] = self::SLUGS[$kind];

        $row = $this->db->table("{$table} as t")
            ->join("{$table} as now", static function ($join) use ($owner): void {
                $join->on("now.{$owner}", '=', "t.{$owner}")->on('now.locale', '=', 't.locale')->where('now.is_current', true);
            })
            ->where('t.locale', $locale)
            ->where('t.slug', $slug)
            ->first(["t.{$owner} as id", 'now.slug']);

        return $row instanceof stdClass ? ['id' => (string) $row->id, 'slug' => (string) $row->slug] : null;
    }

    public function categoryHead(string $storeId, string $locale, ShopperKind $viewer, string $slug, ShopFilters $filters): ?array
    {
        $name = self::column('name', $locale);
        $sideOf = "CASE s.side WHEN 'retail' THEN base.sells_retail ELSE base.sells_wholesale END";

        // Who holds the slug; what the page lists for this viewer; the shopper's choices the page still
        // offers (a brand listed here, an active value of an active attribute held here, a side something
        // here is sold on); then each row with the attributes picked whose values it holds none of - it
        // counts under a value of one of them only when that one is all it misses (several values of one
        // attribute show any of them; two attributes, both).
        $rows = $this->db->select(
            "WITH head AS (SELECT t.category_id AS id, now.slug, other.slug AS other_slug, c.{$name} AS name"
            .' FROM catalog.category_slugs t'
            .' JOIN catalog.category_slugs now ON now.category_id = t.category_id AND now.locale = t.locale AND now.is_current'
            .' JOIN catalog.categories c ON c.id = t.category_id'
            .' LEFT JOIN catalog.category_slugs other ON other.category_id = t.category_id AND other.locale = ? AND other.is_current'
            .' WHERE t.locale = ? AND t.slug = ?),'
            .' listed AS (SELECT l.brand_id, l.value_ids, l.sells_retail, l.sells_wholesale FROM '.self::LISTING.' l, head'
            .' WHERE l.store_id = ? AND l.locale = ? AND l.orderable AND '.self::seenBy($viewer, 'l.')
            .' AND l.in_category_pages AND l.category_path @> ARRAY[head.id::text]),'
            .' brands AS (SELECT DISTINCT brand_id FROM listed WHERE brand_id = ANY(?::bpchar[])),'
            .' chosen AS (SELECT av.id::text AS value_id, av.attribute_id::text AS attribute_id FROM catalog.attribute_values av'
            .' JOIN catalog.attributes a ON a.id = av.attribute_id WHERE av.id = ANY(?::bpchar[]) AND av.is_active AND a.is_active'
            .' AND EXISTS (SELECT 1 FROM listed WHERE av.id::text = ANY(listed.value_ids))),'
            .' side AS (SELECT ?::text AS side WHERE (?::text = \'retail\' AND EXISTS (SELECT 1 FROM listed WHERE sells_retail))'
            .' OR (?::text = \'wholesale\' AND EXISTS (SELECT 1 FROM listed WHERE sells_wholesale))),'
            .' base AS (SELECT listed.*,'
            .' ARRAY(SELECT DISTINCT c.attribute_id FROM chosen c WHERE NOT EXISTS (SELECT 1 FROM chosen d WHERE d.attribute_id = c.attribute_id AND d.value_id = ANY(listed.value_ids))) AS missed,'
            .' (NOT EXISTS (SELECT 1 FROM brands) OR listed.brand_id IN (SELECT brand_id FROM brands)) AS by_brand,'
            ." CASE (SELECT side FROM side) WHEN 'retail' THEN listed.sells_retail WHEN 'wholesale' THEN listed.sells_wholesale ELSE true END AS by_side"
            .' FROM listed)'
            ." SELECT 'head' AS kind, head.id::text AS id, head.name AS name, head.other_slug::text AS swatch, head.slug::text AS group_id, NULL::text AS group_name,"
            .' (EXISTS (SELECT 1 FROM listed))::int AS found, 0 AS group_position, 0 AS position FROM head'
            ." UNION ALL SELECT 'brand', b.id::text, b.{$name}, NULL, NULL, NULL,"
            .' count(*) FILTER (WHERE base.by_side AND cardinality(base.missed) = 0), b.position, b.position'
            .' FROM base JOIN catalog.brands b ON b.id = base.brand_id GROUP BY b.id'
            ." UNION ALL SELECT 'value', av.id::text, av.{$name}, av.swatch, a.id::text, a.{$name},"
            .' count(*) FILTER (WHERE base.by_brand AND base.by_side AND (cardinality(base.missed) = 0 OR base.missed = ARRAY[a.id::text])), a.position, av.position'
            .' FROM base CROSS JOIN LATERAL unnest(base.value_ids) AS v(id)'
            .' JOIN catalog.attribute_values av ON av.id = v.id::bpchar JOIN catalog.attributes a ON a.id = av.attribute_id'
            .' WHERE av.is_active AND a.is_active GROUP BY av.id, a.id'
            ." UNION ALL SELECT 'side', s.side, s.side, NULL, NULL, NULL,"
            ." count(*) FILTER (WHERE base.by_brand AND cardinality(base.missed) = 0 AND {$sideOf}), 0, s.position"
            ." FROM base CROSS JOIN (VALUES ('retail', 1), ('wholesale', 2)) AS s(side, position)"
            // Retail or Wholesale is a company's and a guest's to pick (P14): an individual buys retail only.
            .' WHERE '.($viewer === ShopperKind::Individual ? 'false' : 'true')." AND {$sideOf} GROUP BY s.side, s.position"
            .' ORDER BY 1, 8, 5, 9, 2',
            [
                self::other($locale), $locale, $slug, $storeId, $locale,
                self::array(self::ulids($filters->brands)), self::array(self::ulids($filters->values)),
                $filters->side, $filters->side, $filters->side,
            ],
        );

        $head = null;
        $brandChoices = [];
        $sides = [];
        $names = [];
        $values = [];

        foreach ($rows as $row) {
            $choice = new FilterChoice((string) $row->id, (string) $row->name, $row->swatch === null ? null : (string) $row->swatch, (int) $row->found);

            if ($row->kind === 'head') {
                $head = $row;
            } elseif ($row->kind === 'brand') {
                $brandChoices[] = $choice;
            } elseif ($row->kind === 'side') {
                $sides[] = $choice;
            } else {
                $names[(string) $row->group_id] = (string) $row->group_name;
                $values[(string) $row->group_id][] = $choice;
            }
        }

        return $head instanceof stdClass ? [
            'id' => (string) $head->id,
            'slug' => (string) $head->group_id,
            'other_slug' => $head->swatch === null ? null : (string) $head->swatch,
            'name' => (string) $head->name,
            'lists' => (int) $head->found === 1,
            'filters' => new CategoryFilters(
                $brandChoices,
                array_map(static fn (string $id, string $name): FilterGroup => new FilterGroup($id, $name, $values[$id]), array_keys($names), $names),
                $sides,
            ),
        ] : null;
    }

    public function brandHead(string $locale, string $slug): ?array
    {
        $rows = $this->db->select(
            'SELECT t.brand_id AS id, now.slug, other.slug AS other_slug, b.'.self::column('name', $locale).' AS name, b.is_active, b.logo_media_id,'
            .' b.'.self::column('description', $locale).' AS description'
            .' FROM catalog.brand_slugs t'
            .' JOIN catalog.brand_slugs now ON now.brand_id = t.brand_id AND now.locale = t.locale AND now.is_current'
            .' JOIN catalog.brands b ON b.id = t.brand_id'
            .' LEFT JOIN catalog.brand_slugs other ON other.brand_id = t.brand_id AND other.locale = ? AND other.is_current'
            .' WHERE t.locale = ? AND t.slug = ?',
            [self::other($locale), $locale, $slug],
        );
        $row = $rows[0] ?? null;

        return $row instanceof stdClass ? [
            'id' => (string) $row->id,
            'slug' => (string) $row->slug,
            'other_slug' => $row->other_slug === null ? null : (string) $row->other_slug,
            'name' => (string) $row->name,
            'is_active' => (bool) $row->is_active,
            'logo_media_id' => $row->logo_media_id === null ? null : (string) $row->logo_media_id,
            'description' => self::json($row->description),
        ] : null;
    }

    public function categoryCards(string $storeId, string $locale, ShopperKind $viewer, string $categoryId, ShopFilters $filters, ?Cursor $after, int $limit): CardPage
    {
        $query = $this->inCategoryPages($storeId, $locale, $viewer, $categoryId);
        $brands = self::ulids($filters->brands);
        $values = self::ulids($filters->values);

        // Every brand, a secondary one included (amendment 5(k)); the shopper's filters narrow it:
        // any of the brands picked, and, for each attribute picked, any of its values (P14).
        if ($brands !== []) {
            $query->whereIn('brand_id', $brands);
        }

        if ($values !== []) {
            $query->whereRaw(
                'NOT EXISTS (SELECT 1 FROM catalog.attribute_values c WHERE c.id = ANY(?::bpchar[]) AND NOT EXISTS ('
                .'SELECT 1 FROM catalog.attribute_values d WHERE d.id = ANY(?::bpchar[]) AND d.attribute_id = c.attribute_id AND d.id::text = ANY(value_ids)))',
                [self::array($values), self::array($values)],
            );
        }

        if ($filters->side !== null) {
            $query->where($filters->side === ShopFilters::WHOLESALE ? 'sells_wholesale' : 'sells_retail', true);
        }

        return $this->page($storeId, $query, $locale, $viewer, $after, $limit, $filters->sort);
    }

    public function brandCards(string $storeId, string $locale, ShopperKind $viewer, string $brandId, ?Cursor $after, int $limit): CardPage
    {
        return $this->page($storeId, $this->listed($storeId, $locale, $viewer)->where('brand_id', $brandId), $locale, $viewer, $after, $limit, ShopFilters::BEST_SELLING);
    }

    public function cardsOf(string $storeId, string $locale, ShopperKind $viewer, array $productIds): array
    {
        $ids = self::ulids($productIds);

        if ($ids === []) {
            return [];
        }

        $cards = [];

        foreach ($this->cards($this->listed($storeId, $locale, $viewer)->whereIn('product_id', $ids)->get()->all(), $viewer) as $card) {
            $cards[$card->productId] = $card;
        }

        return array_values(array_filter(array_map(static fn (string $id): ?ProductCard => $cards[$id] ?? null, $ids)));
    }

    public function cardsSharing(string $storeId, string $locale, ShopperKind $viewer, string $column, string $value, string $brandId, array $except, int $limit): array
    {
        $query = $this->listed($storeId, $locale, $viewer)
            ->where($column === 'brand_id' ? 'brand_id' : 'category_id', $value)
            ->where(static fn (Builder $brand): Builder => $brand->where('brand_visible_by_default', true)->orWhere('brand_id', $brandId));

        if ($except !== []) {
            $query->whereNotIn('product_id', $except);
        }

        return $this->cards(self::ordered($query)->limit($limit)->get()->all(), $viewer);
    }

    public function product(string $productId, string $locale): ?array
    {
        if (! Ulids::valid($productId)) {
            return null;
        }

        $name = self::column('name', $locale);
        $row = $this->db->table('catalog.products as p')
            ->join('catalog.brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('catalog.brand_slugs as bs', static function ($join) use ($locale): void {
                $join->on('bs.brand_id', '=', 'b.id')->where('bs.locale', $locale)->where('bs.is_current', true);
            })
            ->leftJoin('catalog.warranties as w', 'w.id', '=', 'p.warranty_id')
            ->leftJoin('catalog.product_slugs as os', static function ($join) use ($locale): void {
                $join->on('os.product_id', '=', 'p.id')->where('os.locale', self::other($locale))->where('os.is_current', true);
            })
            ->where('p.id', strtolower($productId))
            ->first([
                'p.stage', 'p.archived_from', "p.{$name} as name", 'p.'.self::column('description', $locale).' as description', 'p.brand_id', 'p.category_id',
                "b.{$name} as brand_name", 'b.is_active as brand_active', 'bs.slug as brand_slug',
                'w.id as warranty_id', "w.{$name} as warranty_name", 'w.period_months', 'w.'.self::column('terms', $locale).' as warranty_terms',
                'os.slug as other_slug',
            ]);

        if (! $row instanceof stdClass) {
            return null;
        }

        return [
            'stage' => (string) $row->stage,
            'archived_from' => $row->archived_from === null ? null : (string) $row->archived_from,
            'name' => $row->name === null ? null : (string) $row->name,
            'description' => self::json($row->description),
            'brand_id' => (string) $row->brand_id,
            'category_id' => $row->category_id === null ? null : (string) $row->category_id,
            // An inactive brand has no page to link to (amendment 5(m)).
            'brand' => (bool) $row->brand_active && $row->brand_slug !== null ? new ShopLink((string) $row->brand_name, (string) $row->brand_slug) : null,
            'warranty' => $row->warranty_id === null ? null : new ShopWarranty((string) $row->warranty_name, $row->period_months === null ? null : (int) $row->period_months, self::json($row->warranty_terms) ?? []),
            'other_slug' => $row->other_slug === null ? null : (string) $row->other_slug,
        ];
    }

    public function isListed(string $storeId, string $locale, ShopperKind $viewer, string $productId): bool
    {
        return $this->listed($storeId, $locale, $viewer)->where('product_id', $productId)->exists();
    }

    public function gallery(string $productId): array
    {
        return array_values(array_map('strval', $this->db->table('catalog.product_photos')->where('product_id', $productId)->orderBy('position')->pluck('media_id')->all()));
    }

    public function variantPhotos(array $variantIds): array
    {
        $ids = self::ulids($variantIds);
        $photos = [];

        if ($ids === []) {
            return [];
        }

        foreach ($this->db->table('catalog.variant_photos')->whereIn('variant_id', $ids)->orderBy('variant_id')->orderBy('position')->get(['variant_id', 'media_id']) as $row) {
            $photos[(string) $row->variant_id][] = (string) $row->media_id;
        }

        return $photos;
    }

    public function labels(string $storeId, string $locale, string $productId): array
    {
        return array_values(array_map(
            static fn (stdClass $row): CardLabel => new CardLabel((string) $row->name, (string) $row->tone),
            $this->db->table('catalog.store_product_labels as spl')
                ->join('catalog.labels as l', 'l.id', '=', 'spl.label_id')
                ->where('spl.store_id', $storeId)
                ->where('spl.product_id', $productId)
                ->orderBy('l.position')->orderBy('l.id')
                ->get(['l.'.self::column('name', $locale).' as name', 'l.tone'])
                ->all(),
        ));
    }

    public function sellingTerms(string $storeId, string $productId): ?SellingTerms
    {
        $row = $this->db->table('catalog.store_products')->where('store_id', $storeId)->where('product_id', $productId)
            ->first(['retail_minimum', 'retail_maximum', 'wholesale_minimum', 'wholesale_maximum']);

        return $row instanceof stdClass ? new SellingTerms(
            (int) $row->retail_minimum,
            $row->retail_maximum === null ? null : (int) $row->retail_maximum,
            $row->wholesale_minimum === null ? null : (int) $row->wholesale_minimum,
            $row->wholesale_maximum === null ? null : (int) $row->wholesale_maximum,
        ) : null;
    }

    public function variantsOnSale(string $storeId, string $locale, ShopperKind $viewer, string $productId): array
    {
        $variants = $this->db->table('catalog.store_variants as sv')
            ->join('catalog.store_products as sp', static function ($join): void {
                $join->on('sp.store_id', '=', 'sv.store_id')->on('sp.product_id', '=', 'sv.product_id');
            })
            ->join('catalog.variants as v', 'v.id', '=', 'sv.variant_id')
            ->leftJoin('catalog.store_variant_facts as f', static function ($join): void {
                $join->on('f.store_id', '=', 'sv.store_id')->on('f.variant_id', '=', 'sv.variant_id');
            })
            ->where('sv.store_id', $storeId)
            ->where('sv.product_id', $productId)
            ->where('sv.is_active', true)
            ->where('sv.not_available_now', false)
            ->where('sp.not_available_now', false)
            ->where('v.is_archived', false)
            // Individuals buy retail only (amendment 14(e)): a mixed product shows them its retail sizes.
            ->when($viewer === ShopperKind::Individual, static fn (Builder $retail): Builder => $retail->where('sv.sells_retail', true))
            // A company and a guest see both sides: retail and wholesale (§4.5, §8 #33).
            ->orderBy('v.position')->orderBy('v.id')
            ->get(['sv.variant_id', 'sv.sells_retail', 'sv.sells_wholesale', 'v.weight_grams', 'v.length_mm', 'v.width_mm', 'v.height_mm', 'f.ending_soon', 'f.orderable'])
            ->all();

        if ($variants === []) {
            return [];
        }

        $variantIds = array_values(array_map(static fn (stdClass $row): string => (string) $row->variant_id, $variants));
        $name = self::column('name', $locale);
        $choices = [];
        $rows = $this->db->table('catalog.variant_values as vv')
            ->join('catalog.variants as v', 'v.id', '=', 'vv.variant_id')
            ->join('catalog.products as p', 'p.id', '=', 'v.product_id')
            ->join('catalog.attributes as a', 'a.id', '=', 'vv.attribute_id')
            ->join('catalog.attribute_values as av', 'av.id', '=', 'vv.value_id')
            // In the product's own order of its variant attributes (amendment 16(b)): its pickers' order.
            ->leftJoin('catalog.product_attributes as pa', static function ($join): void {
                $join->on('pa.product_id', '=', 'p.id')->on('pa.attribute_id', '=', 'vv.attribute_id');
            })
            ->whereIn('vv.variant_id', $variantIds)
            ->orderBy('pa.position')->orderBy('a.position')->orderBy('a.id')
            ->get(['vv.variant_id', 'a.id as attribute_id', "a.{$name} as attribute", 'av.id as value_id', "av.{$name} as value", 'av.swatch']);

        foreach ($rows as $row) {
            $choices[(string) $row->variant_id][] = new VariantChoice((string) $row->attribute_id, (string) $row->attribute, (string) $row->value_id, (string) $row->value, $row->swatch === null ? null : (string) $row->swatch);
        }

        $details = [];
        $rows = $this->db->table('catalog.variant_details as d')
            ->join('catalog.attributes as a', 'a.id', '=', 'd.attribute_id')
            ->whereIn('d.variant_id', $variantIds)
            ->orderBy('a.position')->orderBy('a.id')
            ->get(['d.variant_id', 'd.'.self::column('text', $locale).' as text', 'd.number', "a.{$name} as name", 'a.'.self::column('unit', $locale).' as unit']);

        foreach ($rows as $row) {
            $value = $row->text !== null ? (string) $row->text : self::number((string) $row->number).($row->unit === null ? '' : ' '.$row->unit);
            $details[(string) $row->variant_id][] = new VariantDetail((string) $row->name, $value);
        }

        return array_values(array_map(static fn (stdClass $row): ShopVariant => new ShopVariant(
            (string) $row->variant_id,
            $choices[(string) $row->variant_id] ?? [],
            (bool) $row->sells_retail,
            (bool) $row->sells_wholesale,
            // Ending soon and orderable now (amendment 16(h)); no facts yet: neither.
            (bool) $row->ending_soon && $row->orderable !== false,
            [],
            $details[(string) $row->variant_id] ?? [],
            $row->weight_grams === null ? null : (int) $row->weight_grams,
            $row->length_mm === null ? null : (int) $row->length_mm,
            $row->width_mm === null ? null : (int) $row->width_mm,
            $row->height_mm === null ? null : (int) $row->height_mm,
        ), $variants));
    }

    public function choices(string $storeId, string $locale, ShopperKind $viewer, string $productId, array $onSaleVariantIds): array
    {
        $name = self::column('name', $locale);
        $rows = $this->db->select(
            "SELECT a.id AS attribute_id, a.{$name} AS attribute, a.is_colour, av.id AS value_id, av.{$name} AS value, av.swatch,"
            .' bool_or(v.id = ANY(?::bpchar[])) AS on_sale'
            .' FROM catalog.variants v JOIN catalog.variant_values vv ON vv.variant_id = v.id'
            .' JOIN catalog.attributes a ON a.id = vv.attribute_id JOIN catalog.attribute_values av ON av.id = vv.value_id'
            .' LEFT JOIN catalog.product_attributes pa ON pa.product_id = v.product_id AND pa.attribute_id = vv.attribute_id'
            .' LEFT JOIN catalog.store_variants sv ON sv.store_id = ? AND sv.variant_id = v.id'
            .' WHERE v.product_id = ? AND NOT v.is_archived'
            // An individual is never offered a size sold wholesale only here (amendment 14(e)).
            .($viewer === ShopperKind::Individual ? ' AND sv.sells_retail IS NOT FALSE' : '')
            .' GROUP BY a.id, av.id, pa.position'
            .' ORDER BY pa.position NULLS LAST, a.position, a.id, av.position, av.id',
            [self::array(self::ulids($onSaleVariantIds)), $storeId, $productId],
        );

        $choices = [];

        foreach ($rows as $row) {
            $choices[(string) $row->attribute_id] ??= ['name' => (string) $row->attribute, 'colour' => (bool) $row->is_colour, 'values' => []];
            $choices[(string) $row->attribute_id]['values'][] = new ShopChoiceValue((string) $row->value_id, (string) $row->value, $row->swatch === null ? null : (string) $row->swatch, (bool) $row->on_sale);
        }

        return array_map(
            static fn (string $id, array $choice): ShopChoice => new ShopChoice($id, $choice['name'], $choice['colour'], $choice['values']),
            array_keys($choices),
            $choices,
        );
    }

    public function picked(string $productId, string $kind): array
    {
        return array_values(array_map('strval', $this->db->table('catalog.product_relations')
            ->where('product_id', $productId)
            ->where('kind', $kind)
            ->orderBy('position')
            ->pluck('related_id')
            ->all()));
    }

    public function wordPairs(array $words): array
    {
        if ($words === []) {
            return [];
        }

        // The words hold letters and digits only (SearchTerms), so none is a LIKE wildcard. The
        // list is short and shared; the words are matched exactly by SearchTerms afterwards.
        $patterns = '{'.implode(',', array_map(static fn (string $word): string => '"%'.$word.'%"', $words)).'}';

        return array_values(array_map(
            static fn (stdClass $row): array => [(string) $row->word_a, (string) $row->word_b],
            $this->db->select(
                'SELECT word_a, word_b FROM catalog.word_pairs WHERE word_a LIKE ANY(?::text[]) OR word_b LIKE ANY(?::text[]) ORDER BY word_a, word_b',
                [$patterns, $patterns],
            ),
        ));
    }

    public function search(string $storeId, string $locale, ShopperKind $viewer, SearchTerms $terms, ?SearchCursor $after, int $limit): SearchResults
    {
        // The nearness text is the page's name, then the other one, a line apart (DatabaseListingRows).
        // Ranked by four keys, the last a page ended on where the next starts (P16): the tier, the
        // nearness inside the nearest tier only, the sales rank — none after every rank — and the id.
        $bindings = [$terms->text, $terms->text, $terms->inNames(), $terms->text, $terms->inWords(), $terms->text, $storeId, $locale, $terms->anywhere(), $terms->text];

        if ($after !== null) {
            $key = [$after->tier, $after->nearness, $after->rank];
            array_push($bindings, ...$key, ...$key, ...[$after->productId]);
        }

        $rows = $this->db->select(
            'SELECT * FROM (SELECT found.*, CASE WHEN tier = 3 THEN -near ELSE 0 END AS k2, COALESCE(sales_rank::bigint, '.SearchCursor::UNRANKED.') AS k3 FROM ('
            .'SELECT l.product_id, l.name, l.slug, l.card_photo, l.sales_rank, l.card_variant_id, l.retail_card_variant_id,'
            .' l.ending_soon_companies, l.ending_soon_individuals, '.self::labelsOf($locale, 'l').' AS labels, '.self::ratingOf('l').','
            .' CASE WHEN split_part(l.search_text, chr(10), 1) = ?::text OR split_part(l.search_text, chr(10), 2) = ?::text THEN 1'
            ." WHEN l.search_document @@ to_tsquery('simple', ?) THEN 2"
            .' WHEN ?::text <% l.search_text THEN 3'
            ." WHEN l.search_document @@ to_tsquery('simple', ?) THEN 4"
            .' ELSE 5 END AS tier,'
            .' round(word_similarity(?::text, l.search_text)::numeric * 100000)::int AS near,'
            .' count(*) OVER () AS total'
            .' FROM '.self::LISTING.' l'
            .' WHERE l.store_id = ? AND l.locale = ? AND l.orderable AND l.brand_visible_by_default AND '.self::seenBy($viewer, 'l.')
            ." AND (l.search_document @@ to_tsquery('simple', ?) OR ?::text <% l.search_text)) found) ranked"
            .($after === null ? '' : ' WHERE (tier, k2, k3) > (?, ?, ?) OR ((tier, k2, k3) = (?, ?, ?) AND product_id < ?)')
            .' ORDER BY tier, k2, k3, product_id DESC LIMIT ?',
            [...$bindings, $limit + 1],
        );

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new SearchResults(
            $this->cards($rows, $viewer),
            $rows === [] ? 0 : (int) $rows[0]->total,
            $more && $last instanceof stdClass ? SearchCursor::after((int) $last->tier, (int) $last->k2, (int) $last->k3, (string) $last->product_id)->text() : null,
        );
    }

    /** What a category's page lists: everything below it in the pages, a product left in one that is off not. */
    private function inCategoryPages(string $storeId, string $locale, ShopperKind $viewer, string $categoryId): Builder
    {
        return $this->listed($storeId, $locale, $viewer)
            ->whereRaw('category_path @> ARRAY[?]::text[]', [$categoryId])
            ->where('in_category_pages', true);
    }

    /** What this viewer can order in this store, in this language, each row with its labels. */
    private function listed(string $storeId, string $locale, ShopperKind $viewer): Builder
    {
        return $this->db->table(self::LISTING)
            ->select(self::LISTING.'.*')
            ->selectRaw(self::labelsOf($locale, self::LISTING).' AS labels')
            ->selectRaw(self::ratingOf(self::LISTING))
            ->where('store_id', $storeId)->where('locale', $locale)->where('orderable', true)->whereRaw(self::seenBy($viewer));
    }

    public function ratingsShown(string $storeId): bool
    {
        return (bool) ($this->db->table('catalog.store_rating_display')->where('store_id', $storeId)->value('shown') ?? true);
    }

    /**
     * A row's rating (amendment 25): the product's pushed sum and count — global — and whether the
     * row's store shows ratings, read in the row's own query, never kept in the listing.
     *
     * @param  'l'|'catalog.listing'  $table
     * @return literal-string
     */
    private static function ratingOf(string $table): string
    {
        return "(SELECT pr.rating_sum FROM catalog.product_ratings pr WHERE pr.product_id = {$table}.product_id) AS rating_sum,"
            ." (SELECT pr.rating_count FROM catalog.product_ratings pr WHERE pr.product_id = {$table}.product_id) AS rating_count,"
            ." COALESCE((SELECT d.shown FROM catalog.store_rating_display d WHERE d.store_id = {$table}.store_id), true) AS ratings_shown";
    }

    /**
     * A row's place in the rating sort (amendment 25; feedback.md §1.2, answer 15): its average pulled
     * toward the store's — `(5 × C + sum) / (5 + count)` — in millionths, so a page's cursor carries it
     * as a whole number; none for a product without reviews, which comes last. `C` is bound.
     *
     * @return literal-string
     */
    private static function ratingKey(): string
    {
        return '(SELECT round(((5 * ?::numeric) + pr.rating_sum) / (5 + pr.rating_count) * 1000000)::bigint'
            .' FROM catalog.product_ratings pr WHERE pr.product_id = '.self::LISTING.'.product_id AND pr.rating_count > 0)';
    }

    /**
     * The store's average, `C` (answer 15): every approved review of the products the store sells —
     * its orderable listing rows, any language, any viewer — or none when none has a review, so every
     * product comes last alike. From the store's listing rows (their key's first column), each product
     * once.
     */
    private function storeAverage(string $storeId): ?string
    {
        $average = $this->db->selectOne(
            'SELECT SUM(pr.rating_sum)::numeric / NULLIF(SUM(pr.rating_count), 0) AS average'
            .' FROM (SELECT DISTINCT product_id FROM '.self::LISTING.' WHERE store_id = ? AND orderable) sold'
            .' JOIN catalog.product_ratings pr ON pr.product_id = sold.product_id',
            [$storeId],
        );

        return $average instanceof stdClass && $average->average !== null ? (string) $average->average : null;
    }

    /**
     * A row's labels, in the list's order as it is now — moving a label in the list rewrites no row
     * (§1.8) — read in the row's own query.
     *
     * @param  'l'|'catalog.listing'  $table
     * @return literal-string
     */
    private static function labelsOf(string $locale, string $table): string
    {
        $name = $locale === 'ar' ? 'name_ar' : 'name_en';

        return "(SELECT json_agg(json_build_object('name', lb.{$name}, 'tone', lb.tone) ORDER BY lb.position, lb.id)"
            ." FROM catalog.labels lb WHERE lb.id = ANY({$table}.label_ids::bpchar[]))";
    }

    /**
     * Whom a row is shown to (catalog.md §1.13, amendment 14): an individual a row individuals see —
     * its category on for them, a variant sold retail — a company a row companies see, and **a guest
     * what either kind of account sees** (P25), so a category switched off for both is seen by no one.
     * A fixed fragment, never a value from the request.
     *
     * @param  ''|'l.'  $table  the listing's alias in the query, if it has one
     * @return literal-string
     */
    private static function seenBy(ShopperKind $viewer, string $table = ''): string
    {
        return match ($viewer) {
            ShopperKind::Individual => "{$table}for_individuals",
            ShopperKind::Company => "{$table}for_companies",
            ShopperKind::Guest => "({$table}for_individuals OR {$table}for_companies)",
        };
    }

    /** Best-selling first — none ranked until Sales pushes ranks (§2.2) — then newest. */
    private static function ordered(Builder $query): Builder
    {
        return $query->orderByRaw('sales_rank ASC NULLS LAST')->orderByDesc('product_id');
    }

    private function page(string $storeId, Builder $query, string $locale, ShopperKind $viewer, ?Cursor $after, int $limit, string $sort): CardPage
    {
        if ($sort === ShopFilters::RATING) {
            return $this->ratedPage($storeId, $query, $viewer, $after, $limit);
        }

        // Newest: by id alone, a ULID being in the order it was made (P15).
        $newest = $sort === ShopFilters::NEWEST;

        if ($after !== null && $newest) {
            $query->where('product_id', '<', $after->productId);
        } elseif ($after !== null) {
            $query->where(static function (Builder $where) use ($after): void {
                if ($after->salesRank === null) {
                    $where->whereNull('sales_rank')->where('product_id', '<', $after->productId);

                    return;
                }

                $where->where('sales_rank', '>', $after->salesRank)
                    ->orWhere(static fn (Builder $tie): Builder => $tie->where('sales_rank', $after->salesRank)->where('product_id', '<', $after->productId))
                    ->orWhereNull('sales_rank');
            });
        }

        $rows = ($newest ? $query->orderByDesc('product_id') : self::ordered($query))->limit($limit + 1)->get()->all();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new CardPage(
            $this->cards($rows, $viewer),
            $more && $last instanceof stdClass ? Cursor::after($newest || $last->sales_rank === null ? null : (int) $last->sales_rank, (string) $last->product_id)->text() : null,
        );
    }

    /**
     * Best-rated first (amendment 25): the weighted key, highest first, products without reviews last,
     * ties and the unrated newest first; keyset on (key, id), the store's average read once a page. A
     * review approved between two pages moves the keys, so a product may show twice or not at all
     * across them — as it does when Sales' ranks change (accepted: lists are browsed, not audited).
     */
    private function ratedPage(string $storeId, Builder $query, ShopperKind $viewer, ?Cursor $after, int $limit): CardPage
    {
        // The key worked out once per row, in a derived table the page then walks.
        $rated = $this->db->table($query->selectRaw(self::ratingKey().' AS rating_key', [$this->storeAverage($storeId)]), 'rated');

        if ($after !== null && $after->salesRank === null) {
            $rated->whereNull('rating_key')->where('product_id', '<', $after->productId);
        } elseif ($after !== null) {
            $rated->where(static fn (Builder $where): Builder => $where->where('rating_key', '<', $after->salesRank)
                ->orWhere(static fn (Builder $tie): Builder => $tie->where('rating_key', $after->salesRank)->where('product_id', '<', $after->productId))
                ->orWhereNull('rating_key'));
        }

        $rows = $rated->orderByRaw('rating_key DESC NULLS LAST')->orderByDesc('product_id')->limit($limit + 1)->get()->all();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new CardPage(
            $this->cards($rows, $viewer),
            $more && $last instanceof stdClass ? Cursor::after($last->rating_key === null ? null : (int) $last->rating_key, (string) $last->product_id)->text() : null,
        );
    }

    /**
     * Each card with the variant it shows to this viewer (amendment 14(f)): the first an individual can
     * buy — a retail one — or, for a company or a guest, the first on sale here (§2.2: companies and
     * guests share a card); its labels, read with it; and its side's "Last pieces" (amendment 16(h)).
     *
     * @param  array<array-key, mixed>  $rows  listing rows, each with its labels
     * @return list<ProductCard>
     */
    private function cards(array $rows, ShopperKind $viewer): array
    {
        $individual = $viewer === ShopperKind::Individual;
        $rows = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass));

        return array_map(static function (stdClass $row) use ($individual): ProductCard {
            /** @var array<string, array<string, string>>|null $photo */
            $photo = self::json($row->card_photo);
            /** @var list<array{name: string, tone: string}> $labels */
            $labels = self::json($row->labels) ?? [];

            return new ProductCard(
                (string) $row->product_id,
                (string) $row->name,
                (string) $row->slug,
                $photo,
                array_map(static fn (array $label): CardLabel => new CardLabel((string) $label['name'], (string) $label['tone']), $labels),
                (string) ($individual ? $row->retail_card_variant_id : $row->card_variant_id),
                (bool) ($individual ? $row->ending_soon_individuals : $row->ending_soon_companies),
                // Stars only where the store shows ratings, and once there is a review (amendment 25).
                (bool) ($row->ratings_shown ?? true) && (int) ($row->rating_count ?? 0) > 0 ? CardRating::of((int) $row->rating_sum, (int) $row->rating_count) : null,
            );
        }, $rows);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function json(mixed $text): ?array
    {
        if ($text === null) {
            return null;
        }

        $decoded = json_decode((string) $text, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The ids that are ULIDs, in small letters; any other finds nothing without asking.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function ulids(array $ids): array
    {
        return array_values(array_unique(array_map('strtolower', array_filter($ids, Ulids::valid(...)))));
    }

    /**
     * A PostgreSQL array of ids. Ids hold no comma, brace or quote.
     *
     * @param  list<string>  $ids
     */
    private static function array(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }

    /** A detail's number as written: no trailing zeros after the point (numeric(12,3)). */
    private static function number(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    private static function other(string $locale): string
    {
        return $locale === 'ar' ? 'en' : 'ar';
    }

    private static function column(string $name, string $locale): string
    {
        return $name.($locale === 'ar' ? '_ar' : '_en');
    }
}
