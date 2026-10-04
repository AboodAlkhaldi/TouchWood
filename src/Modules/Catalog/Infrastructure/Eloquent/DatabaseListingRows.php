<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Domain\Service\ArabicText;
use Modules\Platform\Public\Contracts\PlatformApi;
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
 *   price and the sales rank, pushed by Pricing and Sales, are empty.
 * - **The search reads** the names (A), the search words (B) and the names of its category and of
 *   every category above it (C), **in both languages on every page** (amendment 5(f), (g)), each as
 *   search compares words (handoff §5.2) — never the brand, the code or the description (5(c), (d)).
 *   The nearness text holds the page's name first, then the other one, a line apart.
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
        .' search_text text, words_a text, words_b text, words_c text';

    /** The columns written, in the order the insert names them. */
    private const array COLUMNS = [
        'store_id', 'locale', 'product_id', 'name', 'slug', 'brand_id', 'brand_visible_by_default', 'category_id',
        'category_path', 'in_category_pages', 'value_ids', 'label_ids', 'card_media_id', 'card_photo', 'orderable',
        'price_minor', 'sales_rank', 'search_text', 'search_document',
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
            'DELETE FROM '.self::TABLE.' AS l WHERE l.product_id = ANY(?::text[]) AND NOT EXISTS ('
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
            ." r.orderable, NULL, NULL, r.search_text, {$document}"
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
            ->where('p.hidden_by_brand', false)
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
        $paths = $this->categoryPaths(array_values(array_unique(array_map(static fn (stdClass $product): string => (string) $product->category_id, $products))));
        $words = $this->grouped('catalog.product_search_words', $ids, 'normalized', 'position');
        $filterValues = $this->grouped('catalog.product_filter_values', $ids, 'value_id', 'value_id');
        $variantValues = $this->variantValues(array_merge(...array_values(array_map(static fn (array $stores): array => array_merge(...array_values($stores)), $sellable))));
        $labels = $this->labels($ids);
        $rows = [];

        foreach ($products as $product) {
            $id = (string) $product->id;

            if (! isset($sellable[$id])) {
                continue;
            }

            $card = $this->cardPhoto($id);
            $categoryId = (string) $product->category_id;
            $searched = ['ar' => ArabicText::normalize((string) $product->name_ar), 'en' => ArabicText::normalize((string) $product->name_en)];

            foreach ($sellable[$id] as $storeId => $variantIds) {
                $values = $filterValues[$id] ?? [];

                foreach ($variantIds as $variantId) {
                    array_push($values, ...($variantValues[$variantId] ?? []));
                }

                $values = array_values(array_unique($values));
                sort($values);

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
     * @param  list<string>  $productIds
     * @return array<string, array<string, list<string>>> product => store => variant ids
     */
    private function sellableVariants(array $productIds): array
    {
        $rows = $this->db->table('catalog.store_variants as sv')
            ->join('catalog.store_products as sp', static function ($join): void {
                $join->on('sp.store_id', '=', 'sv.store_id')->on('sp.product_id', '=', 'sv.product_id');
            })
            ->join('catalog.variants as v', 'v.id', '=', 'sv.variant_id')
            ->whereIn('sv.product_id', $productIds)
            ->where('sv.is_active', true)
            ->where('sv.not_available_now', false)
            ->where('sp.not_available_now', false)
            ->where('v.is_archived', false)
            ->orderBy('sv.product_id')->orderBy('sv.store_id')->orderBy('v.position')->orderBy('v.id')
            ->get(['sv.product_id', 'sv.store_id', 'sv.variant_id']);

        $sellable = [];

        foreach ($rows as $row) {
            $sellable[(string) $row->product_id][(string) $row->store_id][] = (string) $row->variant_id;
        }

        return $sellable;
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
            .' SELECT id, id, parent_id, name_ar, name_en, 0 FROM catalog.categories WHERE id = ANY(?::text[])'
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
