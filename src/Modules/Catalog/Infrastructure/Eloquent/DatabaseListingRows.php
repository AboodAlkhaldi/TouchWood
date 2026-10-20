<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Search\SearchTerms;
use Modules\Catalog\Domain\Service\ArabicText;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * The listing's rows, written from Catalog's own tables (catalog.md §5.4).
 *
 * - **A row that stays is changed in place, never written again**: a new row takes a key lock on its
 *   card photo's media row, which deleting that file holds while it waits for the products' lock — as
 *   the gallery's rows (DatabaseProductRepository::replaceGallery). Only a row that is new, or whose
 *   card photo changes, asks for that lock; a row that is no longer wanted is deleted.
 * - **The card photo** is the first photo of the gallery whose sizes are ready, its addresses asked
 *   of Platform as the row is written (§2.4, §9.3 #17), so a grid reads no media.
 * - **Until stage 5 every row is orderable** (§1.3, §2.2: Inventory pushes it from then), and the
 *   sales rank, pushed by Sales, is empty.
 * - **The facts pushed in** (`store_variant_facts`, P22): each side's card price — the price and the
 *   price before of the variant its card shows — and its "Last pieces" (amendment 16(h)): some size
 *   that side can buy and order now is ending soon — any size for companies and guests, a size sold
 *   retail for individuals; a size not orderable (`orderable` false) lights neither.
 * - **The search reads** the names (A), the search words (B) and the names of its category and of
 *   every category above it (C), **in both languages on every page** (amendment 5(f), (g)), each as
 *   search compares words (handoff §5.2) — never the brand, the code or the description (5(c), (d)).
 *   The nearness text holds the page's name first, then the other one, a line apart, each as its
 *   words one space apart (`SearchTerms::canonical`), as what a shopper types is read.
 * - **Who sees it** (§1.13, amendment 14): whether individuals and companies see the product there —
 *   its own category's switches in that store, and for individuals a variant sold retail (14(e)) —
 *   whether any variant there sells retail or wholesale, and each side's card variant (14(f)). A
 *   guest's reads ask for either side (P25), never a column of its own.
 */
final readonly class DatabaseListingRows implements ListingRows
{
    private const string TABLE = 'catalog.listing';

    /** Products read and written in one go — a deactivation may reach every product. */
    private const int CHUNK = 500;

    private const array LOCALES = ['ar', 'en'];

    /** The row's columns, as the JSON the rows are sent in names them. */
    private const string RECORD = 'store_id text, locale text, product_id text, name text, slug text, brand_id text,'
        .' brand_visible_by_default boolean, category_id text, category_path text[], in_category_pages boolean,'
        .' value_ids text[], label_ids text[], card_media_id text, card_photo jsonb, orderable boolean,'
        .' search_text text, words_a text, words_b text, words_c text,'
        .' for_individuals boolean, for_companies boolean, sells_retail boolean, sells_wholesale boolean,'
        .' card_variant_id text, retail_card_variant_id text, ending_soon_companies boolean, ending_soon_individuals boolean,'
        .' price_minor bigint, price_before_minor bigint, retail_price_minor bigint, retail_price_before_minor bigint';

    /** The columns written, in the order the insert names them. */
    private const array COLUMNS = [
        'store_id', 'locale', 'product_id', 'name', 'slug', 'brand_id', 'brand_visible_by_default', 'category_id',
        'category_path', 'in_category_pages', 'value_ids', 'label_ids', 'card_media_id', 'card_photo', 'orderable',
        'price_minor', 'sales_rank', 'search_text', 'search_document',
        'for_individuals', 'for_companies', 'sells_retail', 'sells_wholesale', 'card_variant_id', 'retail_card_variant_id',
        'ending_soon_companies', 'ending_soon_individuals', 'price_before_minor', 'retail_price_minor', 'retail_price_before_minor',
    ];

    public function __construct(
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    public function refresh(array $productIds): void
    {
        $this->requireTransaction();
        $ids = array_values(array_unique(array_map('strtolower', array_filter($productIds, Ulids::valid(...)))));
        sort($ids);

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->write($chunk, $this->rowsOf($chunk));
        }
    }

    public function rebuild(): void
    {
        $this->requireTransaction();

        // Every product that has a row, and every one that might: a ready one.
        $ids = $this->db->table('catalog.products')->where('stage', 'READY')->pluck('id')
            ->merge($this->db->table(self::TABLE)->distinct()->pluck('product_id'))
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $this->refresh(array_values($ids));
    }

    private function requireTransaction(): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException("The listing is written inside the change's transaction, under the products' lock.");
        }
    }

    /**
     * @param  list<string>  $productIds
     * @param  list<array<string, mixed>>  $rows
     */
    private function write(array $productIds, array $rows): void
    {
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ids = '{'.implode(',', $productIds).'}';

        $this->db->delete(
            'DELETE FROM '.self::TABLE.' AS l WHERE l.product_id = ANY(?::bpchar[]) AND NOT EXISTS ('
            .'SELECT 1 FROM jsonb_to_recordset(?::jsonb) AS r('.self::RECORD.')'
            .' WHERE r.store_id = l.store_id AND r.locale = l.locale AND r.product_id = l.product_id)',
            [$ids, $json],
        );

        if ($rows === []) {
            return;
        }

        $document = "setweight(to_tsvector('simple', r.words_a), 'A') || setweight(to_tsvector('simple', r.words_b), 'B')"
            ." || setweight(to_tsvector('simple', r.words_c), 'C')";
        $list = static fn (string $format): string => implode(', ', array_map(static fn (string $column): string => sprintf($format, $column), self::COLUMNS));

        $this->db->insert(
            'INSERT INTO '.self::TABLE.' AS l ('.$list('%s').')'
            .' SELECT r.store_id, r.locale, r.product_id, r.name, r.slug, r.brand_id, r.brand_visible_by_default,'
            .' r.category_id, r.category_path, r.in_category_pages, r.value_ids, r.label_ids, r.card_media_id, r.card_photo,'
            // The rank Sales pushed for the store (amendment 30), none when it has none — cast, so the ranks' key is used.
            .' r.orderable, r.price_minor, (SELECT sr.rank FROM catalog.sales_ranks AS sr WHERE sr.store_id = r.store_id::bpchar AND sr.product_id = r.product_id::bpchar),'
            ." r.search_text, {$document},"
            .' r.for_individuals, r.for_companies, r.sells_retail, r.sells_wholesale, r.card_variant_id, r.retail_card_variant_id,'
            .' r.ending_soon_companies, r.ending_soon_individuals, r.price_before_minor, r.retail_price_minor, r.retail_price_before_minor'
            .' FROM jsonb_to_recordset(?::jsonb) AS r('.self::RECORD.')'
            .' ON CONFLICT (store_id, locale, product_id) DO UPDATE SET '.$list('%1$s = EXCLUDED.%1$s')
            // Unchanged, it is not written at all.
            .' WHERE ('.$list('l.%s').') IS DISTINCT FROM ('.$list('EXCLUDED.%s').')',
            [$json],
        );
    }

    /**
     * @param  list<string>  $productIds
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $productIds): array
    {
        $products = $this->db->table('catalog.products as p')
            ->join('catalog.brands as b', 'b.id', '=', 'p.brand_id')
            ->join('catalog.categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('p.id', $productIds)
            ->where('p.stage', 'READY')
            ->where('p.hidden_by_category', false)
            // A brand's deactivation hides or moves every product of it, never leaves one (§1.6):
            // its being active says whether it hid the product.
            ->where('b.is_active', true)
            ->orderBy('p.id')
            ->get(['p.id', 'p.name_ar', 'p.name_en', 'p.brand_id', 'p.category_id', 'b.show_in_default_listings', 'c.is_active as category_active'])
            ->all();

        if ($products === []) {
            return [];
        }

        $ids = array_values(array_map(static fn (stdClass $product): string => (string) $product->id, $products));
        $sellable = $this->sellableVariants($ids);

        if ($sellable === []) {
            return [];
        }

        $slugs = $this->currentSlugs($ids);
        $categoryIds = array_values(array_unique(array_map(static fn (stdClass $product): string => (string) $product->category_id, $products)));
        $paths = $this->categoryPaths($categoryIds);
        $audiences = $this->audiences($categoryIds);
        $words = $this->grouped('catalog.product_search_words', $ids, 'normalized', 'position');
        $filterValues = $this->grouped('catalog.product_filter_values', $ids, 'value_id', 'value_id');
        $variantIds = [];

        foreach ($sellable as $stores) {
            foreach ($stores as $variants) {
                array_push($variantIds, ...array_column($variants, 'id'));
            }
        }

        $variantValues = $this->variantValues($variantIds);
        $labels = $this->labels($ids);
        $rows = [];

        foreach ($products as $product) {
            $id = (string) $product->id;

            if (! isset($sellable[$id])) {
                continue;
            }

            $card = $this->cardPhoto($id);
            $categoryId = (string) $product->category_id;
            $searched = ['ar' => SearchTerms::canonical((string) $product->name_ar), 'en' => SearchTerms::canonical((string) $product->name_en)];

            foreach ($sellable[$id] as $storeId => $variants) {
                $values = $filterValues[$id] ?? [];

                foreach ($variants as $variant) {
                    array_push($values, ...($variantValues[$variant['id']] ?? []));
                }

                $values = array_values(array_unique($values));
                sort($values);

                // Who sees it here (§1.13, amendment 14): its own category's switches in this store —
                // none, both see it — and, for individuals, who buy retail only, a variant sold retail
                // (14(e)). Each side's card is the first variant, in the product's order, that side can
                // buy (14(f)): any for companies and guests, a retail one for individuals.
                $retail = array_values(array_filter($variants, static fn (array $variant): bool => $variant['retail']));
                $switches = $audiences[$storeId][$categoryId] ?? ['individuals' => true, 'companies' => true];
                $sides = [
                    'for_individuals' => $switches['individuals'] && $retail !== [],
                    'for_companies' => $switches['companies'],
                    'sells_retail' => $retail !== [],
                    'sells_wholesale' => array_filter($variants, static fn (array $variant): bool => $variant['wholesale']) !== [],
                    'card_variant_id' => $variants[0]['id'],
                    'retail_card_variant_id' => $retail[0]['id'] ?? null,
                    // The pushed facts, each side's (P22, amendment 16(h)).
                    'ending_soon_companies' => array_filter($variants, static fn (array $variant): bool => $variant['ending']) !== [],
                    'ending_soon_individuals' => array_filter($retail, static fn (array $variant): bool => $variant['ending']) !== [],
                    'price_minor' => $variants[0]['price'],
                    'price_before_minor' => $variants[0]['before'],
                    'retail_price_minor' => $retail[0]['price'] ?? null,
                    'retail_price_before_minor' => $retail[0]['before'] ?? null,
                ];

                foreach (self::LOCALES as $locale) {
                    $name = (string) $product->{"name_{$locale}"};
                    $slug = $slugs[$id][$locale] ?? throw new LogicException("Ready product {$id} has no current {$locale} slug.");

                    $rows[] = [
                        'store_id' => $storeId,
                        'locale' => $locale,
                        'product_id' => $id,
                        'name' => $name,
                        'slug' => $slug,
                        'brand_id' => (string) $product->brand_id,
                        'brand_visible_by_default' => (bool) $product->show_in_default_listings,
                        'category_id' => $categoryId,
                        'category_path' => $paths[$categoryId]['ids'],
                        'in_category_pages' => (bool) $product->category_active,
                        'value_ids' => $values,
                        'label_ids' => $labels[$storeId][$id] ?? [],
                        'card_media_id' => $card[0] ?? null,
                        'card_photo' => $card[1] ?? null,
                        'orderable' => true,
                        'search_text' => $searched[$locale]."\n".$searched[$locale === 'ar' ? 'en' : 'ar'],
                        'words_a' => implode(' ', $searched),
                        'words_b' => implode(' ', $words[$id] ?? []),
                        'words_c' => $paths[$categoryId]['words'],
                        ...$sides,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * The variants a shopper can order in each store, in the product's order: switched on there,
     * not archived, and neither they nor their product "Not available now" there.
     *
     * Each with how it is sold there (§1.3): retail, wholesale or both; whether it is ending soon and
     * may be ordered now, and its price there, as pushed (P22, amendment 16(h)).
     *
     * @param  list<string>  $productIds
     * @return array<string, array<string, non-empty-list<array{id: string, retail: bool, wholesale: bool, ending: bool, price: int|null, before: int|null}>>> product => store => variants
     */
    private function sellableVariants(array $productIds): array
    {
        $rows = $this->db->table('catalog.store_variants as sv')
            ->join('catalog.store_products as sp', static function ($join): void {
                $join->on('sp.store_id', '=', 'sv.store_id')->on('sp.product_id', '=', 'sv.product_id');
            })
            ->join('catalog.variants as v', 'v.id', '=', 'sv.variant_id')
            ->leftJoin('catalog.store_variant_facts as f', static function ($join): void {
                $join->on('f.store_id', '=', 'sv.store_id')->on('f.variant_id', '=', 'sv.variant_id');
            })
            ->whereIn('sv.product_id', $productIds)
            ->where('sv.is_active', true)
            ->where('sv.not_available_now', false)
            ->where('sp.not_available_now', false)
            ->where('v.is_archived', false)
            ->orderBy('sv.product_id')->orderBy('sv.store_id')->orderBy('v.position')->orderBy('v.id')
            ->get(['sv.product_id', 'sv.store_id', 'sv.variant_id', 'sv.sells_retail', 'sv.sells_wholesale', 'f.orderable', 'f.ending_soon', 'f.price_minor', 'f.price_before_minor']);

        $sellable = [];

        foreach ($rows as $row) {
            $sellable[(string) $row->product_id][(string) $row->store_id][] = [
                'id' => (string) $row->variant_id,
                'retail' => (bool) $row->sells_retail,
                'wholesale' => (bool) $row->sells_wholesale,
                // No facts yet: orderable as §1.3 says, not ending soon, no price.
                'ending' => (bool) $row->ending_soon && $row->orderable !== false,
                'price' => $row->price_minor === null ? null : (int) $row->price_minor,
                'before' => $row->price_before_minor === null ? null : (int) $row->price_before_minor,
            ];
        }

        return $sellable;
    }

    /**
     * Who sees each of these categories in each store that switched it (§1.13, §5.2): a store with no
     * row for a category shows it to both kinds of account.
     *
     * @param  list<string>  $categoryIds
     * @return array<string, array<string, array{individuals: bool, companies: bool}>> store => category => its switches
     */
    private function audiences(array $categoryIds): array
    {
        $audiences = [];

        foreach ($this->db->table('catalog.store_category_audiences')->whereIn('category_id', $categoryIds)->get(['store_id', 'category_id', 'for_individuals', 'for_companies']) as $row) {
            $audiences[(string) $row->store_id][(string) $row->category_id] = ['individuals' => (bool) $row->for_individuals, 'companies' => (bool) $row->for_companies];
        }

        return $audiences;
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, array<string, string>> product => locale => slug
     */
    private function currentSlugs(array $productIds): array
    {
        $slugs = [];

        foreach ($this->db->table('catalog.product_slugs')->whereIn('product_id', $productIds)->where('is_current', true)->get(['product_id', 'locale', 'slug']) as $row) {
            $slugs[(string) $row->product_id][(string) $row->locale] = (string) $row->slug;
        }

        return $slugs;
    }

    /**
     * Each category's ids from the top down to itself, and the names along the way in both
     * languages, as search reads them.
     *
     * @param  list<string>  $categoryIds
     * @return array<string, array{ids: list<string>, words: string}>
     */
    private function categoryPaths(array $categoryIds): array
    {
        $rows = $this->db->select(
            'WITH RECURSIVE up (start_id, id, parent_id, name_ar, name_en, depth) AS ('
            .' SELECT id, id, parent_id, name_ar, name_en, 0 FROM catalog.categories WHERE id = ANY(?::bpchar[])'
            .' UNION ALL SELECT up.start_id, c.id, c.parent_id, c.name_ar, c.name_en, up.depth + 1 FROM catalog.categories c JOIN up ON c.id = up.parent_id'
            .') SELECT start_id, id, name_ar, name_en FROM up ORDER BY start_id, depth DESC',
            ['{'.implode(',', $categoryIds).'}'],
        );

        $paths = [];

        foreach ($rows as $row) {
            $path = $paths[(string) $row->start_id] ?? ['ids' => [], 'words' => ''];
            $path['ids'][] = (string) $row->id;
            $path['words'] = trim($path['words'].' '.ArabicText::normalize((string) $row->name_ar).' '.ArabicText::normalize((string) $row->name_en));
            $paths[(string) $row->start_id] = $path;
        }

        return $paths;
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, list<string>> product => the column's values, in order
     */
    private function grouped(string $table, array $productIds, string $column, string $order): array
    {
        $grouped = [];

        foreach ($this->db->table($table)->whereIn('product_id', $productIds)->orderBy('product_id')->orderBy($order)->get(['product_id', $column]) as $row) {
            $grouped[(string) $row->product_id][] = (string) $row->{$column};
        }

        return $grouped;
    }

    /**
     * Each variant's variant-making values, which count as filters too (amendment 3(a)).
     *
     * @param  list<string>  $variantIds
     * @return array<string, list<string>>
     */
    private function variantValues(array $variantIds): array
    {
        $values = [];

        foreach (array_chunk(array_values(array_unique($variantIds)), self::CHUNK * 4) as $chunk) {
            foreach ($this->db->table('catalog.variant_values')->whereIn('variant_id', $chunk)->get(['variant_id', 'value_id']) as $row) {
                $values[(string) $row->variant_id][] = (string) $row->value_id;
            }
        }

        return $values;
    }

    /**
     * The labels each store attached, in the list's order — the order a card shows them in.
     *
     * @param  list<string>  $productIds
     * @return array<string, array<string, list<string>>> store => product => label ids
     */
    private function labels(array $productIds): array
    {
        $labels = [];
        $rows = $this->db->table('catalog.store_product_labels as spl')
            ->join('catalog.labels as l', 'l.id', '=', 'spl.label_id')
            ->whereIn('spl.product_id', $productIds)
            ->orderBy('l.position')->orderBy('l.id')
            ->get(['spl.store_id', 'spl.product_id', 'spl.label_id']);

        foreach ($rows as $row) {
            $labels[(string) $row->store_id][(string) $row->product_id][] = (string) $row->label_id;
        }

        return $labels;
    }

    /**
     * The first gallery photo whose sizes are ready, and their addresses.
     *
     * @return array{0: string, 1: array<string, array<string, string>>}|array{}
     */
    private function cardPhoto(string $productId): array
    {
        $gallery = $this->db->table('catalog.product_photos')->where('product_id', $productId)->orderBy('position')->pluck('media_id');

        foreach ($gallery as $mediaId) {
            $urls = $this->platform->mediaUrls((string) $mediaId);

            if ($urls !== null && $urls->variants !== []) {
                return [(string) $mediaId, $urls->variants];
            }
        }

        return [];
    }
}
