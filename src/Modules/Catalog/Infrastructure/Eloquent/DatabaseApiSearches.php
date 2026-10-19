<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Catalog\Application\Api\ApiSearches;
use Modules\Catalog\Public\Dto\CategoryPathDto;
use Modules\Catalog\Public\Dto\FoundProductDto;
use Modules\Catalog\Public\Dto\FoundProductsDto;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Catalog\Public\Dto\FoundVariantsDto;
use Modules\Catalog\Public\Dto\VariantValueDto;
use Modules\Catalog\Public\Dto\WholesaleTermsDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * `ApiSearches` in SQL (catalog.md amendment 23).
 *
 * - **A page is two queries**: its rows - each with its values and photo read beside it - and its
 *   total; a list of ids is one bound array (`= ANY(?)`), never a parameter an id, so a thousand sizes
 *   named in `only` cost what one does.
 * - **The order is the product's name in the page's language** - the English one, else the Arabic for
 *   a draft that has none -, then the product, then the size's place: the ids compared `COLLATE "C"`,
 *   byte by byte, as every keyset in Catalog is (lesson 132), the name in the database's own order on
 *   both sides of the comparison.
 * - **The cursor is the last row's keys**, written as JSON in URL-safe base64: opaque to the caller,
 *   read back here; one that is not one starts at the top.
 */
final readonly class DatabaseApiSearches implements ApiSearches
{
    public const int LIMIT_MAX = 100;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function variants(string $storeId, ?string $text, ?array $only, ?array $except, bool $soldWholesale, bool $anyReady, ?string $after, int $limit, string $locale, bool $notArchived = false): FoundVariantsDto
    {
        $store = strtolower($storeId);
        $onlyIds = $only === null ? null : self::ids($only);

        if ($onlyIds === []) {
            return new FoundVariantsDto([], null, 0);
        }

        $name = self::name($locale);
        $query = $this->db->table('catalog.variants as v')
            ->join('catalog.products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('catalog.store_variants as sv', static function ($join) use ($store): void {
                $join->on('sv.variant_id', '=', 'v.id')->where('sv.store_id', '=', $store);
            });

        if ($onlyIds !== null) {
            $query->whereRaw('v.id = ANY(?::text[])', [self::array($onlyIds)]);
        } elseif ($anyReady) {
            $query->where('p.stage', ProductStage::Ready->value)->where('v.is_archived', false);
        } else {
            $query->where('sv.is_active', true);
        }

        $exceptIds = $except === null ? [] : self::ids($except);

        if ($exceptIds !== []) {
            $query->whereRaw('NOT (v.id = ANY(?::text[]))', [self::array($exceptIds)]);
        }

        if ($soldWholesale) {
            $query->where('sv.sells_wholesale', true);
        }

        // Neither the size nor its product archived (Pricing's Needs a Price, step 2).
        if ($notArchived) {
            $query->where('v.is_archived', false)->where('p.stage', '<>', ProductStage::Archived->value);
        }

        if ($text !== null) {
            $like = '%'.addcslashes($text, '\\%_').'%';
            $query->where(static function (Builder $where) use ($text, $like): void {
                $where->where('p.name_ar', 'ilike', $like)->orWhere('p.name_en', 'ilike', $like);

                // A code typed finds the size carrying it.
                if (preg_match('/\A[0-9]{1,10}\z/', $text) === 1) {
                    $where->orWhere('v.code', $text);
                }
            });
        }

        $total = (clone $query)->count();
        $cursor = self::cursor($after, 4);

        if ($cursor !== null) {
            $query->whereRaw("({$name}, p.id COLLATE \"C\", v.position, v.id COLLATE \"C\") > (?, ?, ?, ?)", $cursor);
        }

        $rows = $query
            ->select(['v.id', 'v.product_id', 'p.name_ar', 'p.name_en', 'p.stage', 'v.code', 'v.position', 'v.is_archived', 'sv.is_active'])
            ->selectRaw("{$name} as name_key")
            ->selectRaw('coalesce((SELECT vp.media_id FROM catalog.variant_photos vp WHERE vp.variant_id = v.id ORDER BY vp.position LIMIT 1),'
                .' (SELECT ph.media_id FROM catalog.product_photos ph WHERE ph.product_id = p.id ORDER BY ph.position LIMIT 1)) as photo')
            // Its values, in the product's order of its variant attributes (amendment 16(b)), as `variants()` reads them.
            ->selectRaw("(SELECT coalesce(json_agg(json_build_object('attribute_id', vv.attribute_id, 'attribute_ar', a.name_ar, 'attribute_en', a.name_en,"
                ." 'value_id', vv.value_id, 'value_ar', av.name_ar, 'value_en', av.name_en) ORDER BY pa.position, vv.attribute_id), '[]')"
                .' FROM catalog.variant_values vv'
                .' JOIN catalog.attributes a ON a.id = vv.attribute_id'
                .' JOIN catalog.attribute_values av ON av.id = vv.value_id'
                .' LEFT JOIN catalog.product_attributes pa ON pa.product_id = v.product_id AND pa.attribute_id = vv.attribute_id'
                .' WHERE vv.variant_id = v.id) as variant_values')
            ->orderByRaw("{$name}, p.id COLLATE \"C\", v.position, v.id COLLATE \"C\"")
            ->limit(self::limit($limit) + 1)
            ->get()
            ->all();

        $more = count($rows) > self::limit($limit);
        $rows = array_slice($rows, 0, self::limit($limit));
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new FoundVariantsDto(
            array_map(static fn (stdClass $row): FoundVariantDto => new FoundVariantDto(
                (string) $row->id,
                (string) $row->product_id,
                (string) $row->name_ar,
                $row->name_en === null ? null : (string) $row->name_en,
                $row->photo === null ? null : (string) $row->photo,
                (string) $row->code,
                self::values($row->variant_values),
                (int) $row->position,
                (bool) $row->is_archived || (string) $row->stage === ProductStage::Archived->value,
                $row->is_active === true,
            ), $rows),
            $more && $last !== null ? self::encode([(string) $last->name_key, (string) $last->product_id, (int) $last->position, (string) $last->id]) : null,
            $total,
        );
    }

    public function products(string $storeId, ?string $text, bool $readySwitchedOn, ?array $only, ?string $after, int $limit, string $locale): FoundProductsDto
    {
        $onlyIds = $only === null ? null : self::ids($only);

        if ($onlyIds === []) {
            return new FoundProductsDto([], null, 0);
        }

        $query = $this->db->table('catalog.products as p');

        if ($readySwitchedOn) {
            $query->where('p.stage', ProductStage::Ready->value)
                ->whereRaw('EXISTS (SELECT 1 FROM catalog.store_variants sv WHERE sv.product_id = p.id AND sv.store_id = ? AND sv.is_active)', [strtolower($storeId)]);
        }

        if ($onlyIds !== null) {
            $query->whereRaw('p.id = ANY(?::text[])', [self::array($onlyIds)]);
        }

        return $this->productPage($query, $text, $after, $limit, $locale);
    }

    public function productsInCategory(string $categoryId, ?string $text, ?string $after, int $limit, string $locale): FoundProductsDto
    {
        $id = strtolower(trim($categoryId));

        if (! Ulids::valid($id)) {
            return new FoundProductsDto([], null, 0);
        }

        // The category and every one below it; the depth only guards the walk, the tree is kept acyclic.
        $query = $this->db->table('catalog.products as p')->whereRaw(
            'p.category_id IN (WITH RECURSIVE down AS (SELECT c.id, 0 AS depth FROM catalog.categories c WHERE c.id = ?'
            .' UNION ALL SELECT c.id, down.depth + 1 FROM catalog.categories c JOIN down ON c.parent_id = down.id WHERE down.depth < 100)'
            .' SELECT id FROM down)',
            [$id],
        );

        return $this->productPage($query, $text, $after, $limit, $locale);
    }

    public function categoriesWithPaths(array $ids): array
    {
        $asked = self::ids($ids);

        if ($asked === []) {
            return [];
        }

        $rows = $this->db->select(
            'WITH RECURSIVE up AS ('
            .' SELECT c.id AS start, c.id, c.parent_id, c.name_ar, c.name_en, 0 AS depth FROM catalog.categories c WHERE c.id = ANY(?::text[])'
            .' UNION ALL'
            .' SELECT up.start, c.id, c.parent_id, c.name_ar, c.name_en, up.depth + 1 FROM catalog.categories c JOIN up ON c.id = up.parent_id'
            .' WHERE up.depth < 100'
            .') SELECT up.start, up.name_ar, up.name_en, up.depth, s.name_ar AS own_ar, s.name_en AS own_en, s.is_active'
            .' FROM up JOIN catalog.categories s ON s.id = up.start'
            .' ORDER BY up.start, up.depth DESC',
            [self::array($asked)],
        );

        /** @var array<string, array{own: stdClass, path: list<TranslatedTextDto>}> $found */
        $found = [];

        foreach ($rows as $row) {
            $start = (string) $row->start;
            $found[$start] ??= ['own' => $row, 'path' => []];
            $found[$start]['path'][] = new TranslatedTextDto((string) $row->name_ar, (string) $row->name_en);
        }

        $paths = [];

        // In the order asked.
        foreach ($asked as $id) {
            if (! isset($found[$id]) || $found[$id]['path'] === []) {
                continue;
            }

            $own = $found[$id]['own'];
            $paths[$id] = new CategoryPathDto($id, (string) $own->own_ar, (string) $own->own_en, (bool) $own->is_active, $found[$id]['path']);
        }

        return $paths;
    }

    public function productIdsInCategories(array $categoryIds): array
    {
        $asked = self::ids($categoryIds);
        $found = array_fill_keys($asked, []);

        if ($asked === []) {
            return [];
        }

        // Each category asked, with every one below it, joined to their products: one query for all.
        $rows = $this->db->select(
            'WITH RECURSIVE tree AS ('
            .' SELECT c.id AS root, c.id FROM catalog.categories c WHERE c.id = ANY(?::text[])'
            .' UNION'
            .' SELECT t.root, c.id FROM catalog.categories c JOIN tree t ON c.parent_id = t.id'
            .') SELECT DISTINCT tree.root, p.id FROM tree JOIN catalog.products p ON p.category_id = tree.id ORDER BY tree.root, p.id',
            [self::array($asked)],
        );

        foreach ($rows as $row) {
            $found[(string) $row->root][] = (string) $row->id;
        }

        return $found;
    }

    public function activeCategoryIds(): array
    {
        return array_values(array_map(static fn (mixed $id): string => (string) $id, $this->db->table('catalog.categories')->where('is_active', true)->orderBy('id')->pluck('id')->all()));
    }

    public function wholesaleOf(string $storeId, array $variantIds): array
    {
        $ids = self::ids($variantIds);

        if ($ids === []) {
            return [];
        }

        $store = strtolower($storeId);
        $terms = [];

        foreach ($this->db->select(
            'SELECT v.id, sp.wholesale_minimum, sp.wholesale_maximum, coalesce(sv.sells_wholesale, false) AS sold, coalesce(sv.sells_retail, false) AS sold_retail'
            .' FROM catalog.variants v'
            .' LEFT JOIN catalog.store_products sp ON sp.product_id = v.product_id AND sp.store_id = ?'
            .' LEFT JOIN catalog.store_variants sv ON sv.variant_id = v.id AND sv.store_id = ?'
            .' WHERE v.id = ANY(?::text[])',
            [$store, $store, self::array($ids)],
        ) as $row) {
            $terms[(string) $row->id] = new WholesaleTermsDto(
                (string) $row->id,
                $row->wholesale_minimum === null ? null : (int) $row->wholesale_minimum,
                $row->wholesale_maximum === null ? null : (int) $row->wholesale_maximum,
                (bool) $row->sold,
                (bool) $row->sold_retail,
            );
        }

        return $terms;
    }

    /**
     * A page of products from a query already narrowed: found by text, by name, keyset-paged, with the
     * total.
     */
    private function productPage(Builder $query, ?string $text, ?string $after, int $limit, string $locale): FoundProductsDto
    {
        $name = self::name($locale);

        if ($text !== null) {
            $like = '%'.addcslashes($text, '\\%_').'%';
            $query->where(static function (Builder $where) use ($text, $like): void {
                $where->where('p.name_ar', 'ilike', $like)->orWhere('p.name_en', 'ilike', $like);

                // A code typed finds the product holding it (as the products list, S8).
                if (preg_match('/\A[0-9]{1,10}\z/', $text) === 1) {
                    $where->orWhereExists(static fn (Builder $codes) => $codes->selectRaw('1')->from('catalog.product_codes as c')->whereColumn('c.product_id', 'p.id')->where('c.code', $text));
                }
            });
        }

        $total = (clone $query)->count();
        $cursor = self::cursor($after, 2);

        if ($cursor !== null) {
            $query->whereRaw("({$name}, p.id COLLATE \"C\") > (?, ?)", $cursor);
        }

        $rows = $query
            ->select(['p.id', 'p.name_ar', 'p.name_en', 'p.stage'])
            ->selectRaw("{$name} as name_key")
            ->selectRaw('(SELECT ph.media_id FROM catalog.product_photos ph WHERE ph.product_id = p.id ORDER BY ph.position LIMIT 1) as photo')
            ->selectRaw("(SELECT coalesce(json_agg(DISTINCT v.code ORDER BY v.code), '[]') FROM catalog.variants v WHERE v.product_id = p.id) as codes")
            ->orderByRaw("{$name}, p.id COLLATE \"C\"")
            ->limit(self::limit($limit) + 1)
            ->get()
            ->all();

        $more = count($rows) > self::limit($limit);
        $rows = array_slice($rows, 0, self::limit($limit));
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new FoundProductsDto(
            array_map(static fn (stdClass $row): FoundProductDto => new FoundProductDto(
                (string) $row->id,
                (string) $row->name_ar,
                $row->name_en === null ? null : (string) $row->name_en,
                $row->photo === null ? null : (string) $row->photo,
                self::strings($row->codes),
                ProductStage::from((string) $row->stage),
            ), $rows),
            $more && $last !== null ? self::encode([(string) $last->name_key, (string) $last->id]) : null,
            $total,
        );
    }

    /**
     * The product's name a list orders by: the page's language, the Arabic for a draft with no English.
     *
     * @return literal-string written into the query: one of two fixed expressions, never a value
     */
    private static function name(string $locale): string
    {
        return $locale === 'en' ? 'coalesce(p.name_en, p.name_ar)' : 'p.name_ar';
    }

    private static function limit(int $limit): int
    {
        return min(max($limit, 1), self::LIMIT_MAX);
    }

    /**
     * @param  list<string|int>  $keys
     */
    private static function encode(array $keys): string
    {
        return rtrim(strtr(base64_encode(json_encode($keys, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /**
     * The keys a cursor holds - a name, then ids and places as the order has them - or null for one
     * that is not one of this order's.
     *
     * @return list<string|int>|null
     */
    private static function cursor(?string $after, int $count): ?array
    {
        if ($after === null || $after === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $after) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($after, '-_', '+/'), true);
        $keys = $json === false ? null : json_decode($json, true);

        if (! is_array($keys) || ! array_is_list($keys) || count($keys) !== $count || ! is_string($keys[0])) {
            return null;
        }

        $id = static fn (mixed $key): bool => is_string($key) && Ulids::valid($key);

        // [name, product id] or [name, product id, place, size id].
        $valid = $count === 2
            ? $id($keys[1])
            : $id($keys[1]) && is_int($keys[2]) && $id($keys[3]);

        return $valid ? $keys : null;
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

    /**
     * A list of ids as one PostgreSQL array: ULIDs hold letters and digits only, so nothing in them
     * needs quoting.
     *
     * @param  list<string>  $ids
     */
    private static function array(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }

    /**
     * @return list<VariantValueDto>
     */
    private static function values(mixed $json): array
    {
        $decoded = $json === null ? [] : json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        $values = [];

        foreach (is_array($decoded) ? $decoded : [] as $value) {
            if (! is_array($value)) {
                continue;
            }

            $values[] = new VariantValueDto(
                self::field($value, 'attribute_id'),
                new TranslatedTextDto(self::field($value, 'attribute_ar'), self::field($value, 'attribute_en')),
                self::field($value, 'value_id'),
                new TranslatedTextDto(self::field($value, 'value_ar'), self::field($value, 'value_en')),
            );
        }

        return $values;
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function field(array $value, string $key): string
    {
        $field = $value[$key] ?? '';

        return is_scalar($field) ? (string) $field : '';
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $json): array
    {
        $decoded = $json === null ? [] : json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

        return array_values(array_map('strval', array_filter(is_array($decoded) ? $decoded : [], 'is_scalar')));
    }
}
