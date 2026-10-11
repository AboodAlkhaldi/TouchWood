<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Catalog\Application\Query\Shop\CardLabel;
use Modules\Catalog\Application\Query\Shop\CardPage;
use Modules\Catalog\Application\Query\Shop\Cursor;
use Modules\Catalog\Application\Query\Shop\ProductCard;
use Modules\Catalog\Application\Query\Shop\SearchResults;
use Modules\Catalog\Application\Query\Shop\ShopReader;
use Modules\Catalog\Application\Query\Shop\ShopVariant;
use Modules\Catalog\Application\Query\Shop\VariantChoice;
use Modules\Catalog\Application\Search\SearchTerms;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * The shopper's reads, from the listing (catalog.md §5.4): a grid is one indexed query on it, with
 * the labels of the whole page in a second. Ids from a request that are not ULIDs find nothing
 * without asking the database. Names come in the page's language — `ar` or `en`, nothing else is
 * ever written into a column name here.
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

    public function menu(string $storeId, ?string $baseStoreId, string $locale): array
    {
        $name = self::column('name', $locale);
        $bindings = [$storeId, $locale, $locale, $storeId, $baseStoreId];

        $rows = $this->db->select(
            'WITH listed AS (SELECT DISTINCT unnest(category_path) AS id FROM '.self::LISTING
            .' WHERE store_id = ? AND locale = ? AND in_category_pages AND orderable)'
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

    public function category(string $categoryId, string $locale): ?array
    {
        $row = $this->db->table('catalog.categories')->where('id', $categoryId)->first([self::column('name', $locale).' as name']);

        return $row instanceof stdClass ? ['name' => (string) $row->name] : null;
    }

    public function categoryLists(string $storeId, string $locale, string $categoryId): bool
    {
        return $this->inCategoryPages($storeId, $locale, $categoryId)->exists();
    }

    public function brand(string $brandId, string $locale): ?array
    {
        $row = $this->db->table('catalog.brands')->where('id', $brandId)->first([self::column('name', $locale).' as name', 'is_active', 'logo_media_id']);

        return $row instanceof stdClass
            ? ['name' => (string) $row->name, 'is_active' => (bool) $row->is_active, 'logo_media_id' => $row->logo_media_id === null ? null : (string) $row->logo_media_id]
            : null;
    }

    public function categoryCards(string $storeId, string $locale, string $categoryId, array $brandIds, ?Cursor $after, int $limit): CardPage
    {
        $query = $this->inCategoryPages($storeId, $locale, $categoryId);
        $brandIds = array_values(array_filter($brandIds, Ulids::valid(...)));

        // Every brand, a secondary one included (amendment 5(k)); the shopper's filter narrows it.
        if ($brandIds !== []) {
            $query->whereIn('brand_id', $brandIds);
        }

        return $this->page($query, $locale, $after, $limit);
    }

    public function brandCards(string $storeId, string $locale, string $brandId, ?Cursor $after, int $limit): CardPage
    {
        return $this->page($this->listed($storeId, $locale)->where('brand_id', $brandId), $locale, $after, $limit);
    }

    public function cardsOf(string $storeId, string $locale, array $productIds): array
    {
        $ids = array_values(array_filter($productIds, Ulids::valid(...)));

        if ($ids === []) {
            return [];
        }

        $cards = [];

        foreach ($this->cards($this->listed($storeId, $locale)->whereIn('product_id', $ids)->get()->all(), $locale) as $card) {
            $cards[$card->productId] = $card;
        }

        return array_values(array_filter(array_map(static fn (string $id): ?ProductCard => $cards[$id] ?? null, $ids)));
    }

    public function cardsSharing(string $storeId, string $locale, string $column, string $value, string $brandId, array $except, int $limit): array
    {
        $query = $this->listed($storeId, $locale)
            ->where($column === 'brand_id' ? 'brand_id' : 'category_id', $value)
            ->where(static fn (Builder $brand): Builder => $brand->where('brand_visible_by_default', true)->orWhere('brand_id', $brandId));

        if ($except !== []) {
            $query->whereNotIn('product_id', $except);
        }

        return $this->cards(self::ordered($query)->limit($limit)->get()->all(), $locale);
    }

    public function product(string $productId, string $locale): ?array
    {
        if (! Ulids::valid($productId)) {
            return null;
        }

        $row = $this->db->table('catalog.products')->where('id', strtolower($productId))
            ->first(['stage', 'archived_from', self::column('name', $locale).' as name', self::column('description', $locale).' as description', 'brand_id', 'category_id']);

        if (! $row instanceof stdClass) {
            return null;
        }

        /** @var array<string, mixed>|null $description */
        $description = $row->description === null ? null : json_decode((string) $row->description, true, 512, JSON_THROW_ON_ERROR);

        return [
            'stage' => (string) $row->stage,
            'archived_from' => $row->archived_from === null ? null : (string) $row->archived_from,
            'name' => $row->name === null ? null : (string) $row->name,
            'description' => $description,
            'brand_id' => (string) $row->brand_id,
            'category_id' => $row->category_id === null ? null : (string) $row->category_id,
        ];
    }

    public function isListed(string $storeId, string $locale, string $productId): bool
    {
        return $this->listed($storeId, $locale)->where('product_id', $productId)->exists();
    }

    public function gallery(string $productId): array
    {
        return array_values(array_map('strval', $this->db->table('catalog.product_photos')->where('product_id', $productId)->orderBy('position')->pluck('media_id')->all()));
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

    public function variantsOnSale(string $storeId, string $locale, string $productId): array
    {
        $variantIds = array_values(array_map('strval', $this->db->table('catalog.store_variants as sv')
            ->join('catalog.store_products as sp', static function ($join): void {
                $join->on('sp.store_id', '=', 'sv.store_id')->on('sp.product_id', '=', 'sv.product_id');
            })
            ->join('catalog.variants as v', 'v.id', '=', 'sv.variant_id')
            ->where('sv.store_id', $storeId)
            ->where('sv.product_id', $productId)
            ->where('sv.is_active', true)
            ->where('sv.not_available_now', false)
            ->where('sp.not_available_now', false)
            ->where('v.is_archived', false)
            ->orderBy('v.position')->orderBy('v.id')
            ->pluck('sv.variant_id')
            ->all()));

        if ($variantIds === []) {
            return [];
        }

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

        return array_map(static fn (string $id): ShopVariant => new ShopVariant($id, $choices[$id] ?? []), $variantIds);
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

    public function search(string $storeId, string $locale, SearchTerms $terms, int $limit): SearchResults
    {
        // The nearness text is the page's name, then the other one, a line apart (DatabaseListingRows).
        $rows = $this->db->select(
            'SELECT * FROM (SELECT l.product_id, l.name, l.slug, l.card_photo, l.label_ids, l.sales_rank,'
            .' CASE WHEN split_part(l.search_text, chr(10), 1) = ?::text OR split_part(l.search_text, chr(10), 2) = ?::text THEN 1'
            ." WHEN l.search_document @@ to_tsquery('simple', ?) THEN 2"
            .' WHEN ?::text <% l.search_text THEN 3'
            ." WHEN l.search_document @@ to_tsquery('simple', ?) THEN 4"
            .' ELSE 5 END AS tier,'
            .' word_similarity(?::text, l.search_text) AS nearness,'
            .' count(*) OVER () AS total'
            .' FROM '.self::LISTING.' l'
            .' WHERE l.store_id = ? AND l.locale = ? AND l.orderable AND l.brand_visible_by_default'
            ." AND (l.search_document @@ to_tsquery('simple', ?) OR ?::text <% l.search_text)) found"
            .' ORDER BY tier, CASE WHEN tier = 3 THEN nearness END DESC NULLS LAST, sales_rank ASC NULLS LAST, product_id DESC'
            .' LIMIT ?',
            [$terms->text, $terms->text, $terms->inNames(), $terms->text, $terms->inWords(), $terms->text, $storeId, $locale, $terms->anywhere(), $terms->text, $limit],
        );

        return new SearchResults($this->cards($rows, $locale), $rows === [] ? 0 : (int) $rows[0]->total);
    }

    /** What a category's page lists: everything below it in the pages, a product left in one that is off not. */
    private function inCategoryPages(string $storeId, string $locale, string $categoryId): Builder
    {
        return $this->listed($storeId, $locale)
            ->whereRaw('category_path @> ARRAY[?]::text[]', [$categoryId])
            ->where('in_category_pages', true);
    }

    /** What a shopper can order in this store, in this language. */
    private function listed(string $storeId, string $locale): Builder
    {
        return $this->db->table(self::LISTING)->where('store_id', $storeId)->where('locale', $locale)->where('orderable', true);
    }

    /** Best-selling first — none ranked until Sales pushes ranks (§2.2) — then newest. */
    private static function ordered(Builder $query): Builder
    {
        return $query->orderByRaw('sales_rank ASC NULLS LAST')->orderByDesc('product_id');
    }

    private function page(Builder $query, string $locale, ?Cursor $after, int $limit): CardPage
    {
        if ($after !== null) {
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

        $rows = self::ordered($query)->limit($limit + 1)->get()->all();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new CardPage(
            $this->cards($rows, $locale),
            $more && $last instanceof stdClass ? Cursor::after($last->sales_rank === null ? null : (int) $last->sales_rank, (string) $last->product_id)->text() : null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $rows  listing rows
     * @return list<ProductCard>
     */
    private function cards(array $rows, string $locale): array
    {
        $rows = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass));
        $labelIds = [];

        foreach ($rows as $row) {
            array_push($labelIds, ...self::ids($row->label_ids));
        }

        $labels = [];

        // In the list's order as it is now: moving a label in the list rewrites no row (§1.8).
        if ($labelIds !== []) {
            foreach ($this->db->table('catalog.labels')->whereIn('id', array_values(array_unique($labelIds)))->orderBy('position')->orderBy('id')->get(['id', self::column('name', $locale).' as name', 'tone']) as $label) {
                $labels[(string) $label->id] = new CardLabel((string) $label->name, (string) $label->tone);
            }
        }

        return array_map(static function (stdClass $row) use ($labels): ProductCard {
            /** @var array<string, array<string, string>>|null $photo */
            $photo = $row->card_photo === null ? null : json_decode((string) $row->card_photo, true, 512, JSON_THROW_ON_ERROR);

            return new ProductCard(
                (string) $row->product_id,
                (string) $row->name,
                (string) $row->slug,
                $photo,
                array_values(array_intersect_key($labels, array_flip(self::ids($row->label_ids)))),
            );
        }, $rows);
    }

    /**
     * A text[] as PostgreSQL sends it: `{a,b}`. Ids hold no comma, brace or quote.
     *
     * @return list<string>
     */
    private static function ids(mixed $array): array
    {
        $inner = trim((string) $array, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }

    private static function column(string $name, string $locale): string
    {
        return $name.($locale === 'ar' ? '_ar' : '_en');
    }
}
