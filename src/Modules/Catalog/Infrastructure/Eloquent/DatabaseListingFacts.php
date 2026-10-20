<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Catalog\Public\Dto\ListingPrice;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * `ListingFacts`, received (catalog.md §2.2, §5.2; amendments 15, 16(h), 16(i)): each fact pushed is
 * kept in `catalog.store_variant_facts`, Catalog's own table, inside the caller's transaction, so a
 * listing written again from Catalog's tables never loses it. Only the facts given are written: a
 * price leaves whether the variant is orderable as it was, and the other way round. A variant that
 * is not Catalog's is left out. **The listing reads them** (P22, amendment 16(h)): each push writes
 * the listing rows of the products it touched again, under the products' lock — "Last pieces" on
 * the cards, each side's card price kept for Pricing's stage — and so replaces the shop's cached
 * pages' version (DatabaseListLocks).
 */
final readonly class DatabaseListingFacts implements ListingFacts
{
    private const string TABLE = 'catalog.store_variant_facts';

    public function __construct(
        private ConnectionInterface $db,
        private ListLocks $locks,
        private ListingRows $listingRows,
    ) {}

    public function orderable(StoreId $store, array $variantIds, bool $orderable): void
    {
        $this->write($store, array_fill_keys($variantIds, ['orderable' => $orderable]));
    }

    public function endingSoon(StoreId $store, array $variantIds, bool $endingSoon): void
    {
        $this->write($store, array_fill_keys($variantIds, ['ending_soon' => $endingSoon]));
    }

    public function prices(StoreId $store, array $prices): void
    {
        $this->write($store, array_map(static fn (?ListingPrice $price): array => [
            'price_minor' => $price?->now->minorUnits,
            'price_before_minor' => $price?->before?->minorUnits,
            'currency' => $price?->now->currencyCode,
        ], $prices));
    }

    public function salesRanks(StoreId $store, array $ranks): void
    {
        $rows = [];
        $now = CarbonImmutable::now();

        foreach ($ranks as $productId => $rank) {
            $id = strtolower(trim((string) $productId));

            if (Ulids::valid($id) && $rank >= 1) {
                $rows[$id] = ['store_id' => $store->value, 'product_id' => $id, 'rank' => $rank, 'updated_at' => $now];
            }
        }

        // The store's whole ranking at once (amendment 30): a product left out has no rank there now. Inside the
        // products' lock, as every change the shop's lists read takes it — which also replaces the cached
        // pages' version (DatabaseListLocks).
        $this->db->transaction(function () use ($store, $rows): void {
            $this->locks->lock(ListLocks::PRODUCTS);
            $this->db->table('catalog.sales_ranks')->where('store_id', $store->value)->delete();
            $known = $rows === [] ? [] : $this->db->table('catalog.products')->whereIn('id', array_keys($rows))->pluck('id')->map(strval(...))->all();
            $kept = array_values(array_intersect_key($rows, array_flip($known)));

            foreach (array_chunk($kept, 1000) as $chunk) {
                $this->db->table('catalog.sales_ranks')->insert($chunk);
            }

            // The listing's rows of the store take their rank now; a row rewritten later takes it from the table.
            $this->db->update(
                'UPDATE catalog.listing AS l SET sales_rank = r.rank FROM (SELECT l2.store_id, l2.locale, l2.product_id, sr.rank'
                .' FROM catalog.listing AS l2 LEFT JOIN catalog.sales_ranks AS sr ON sr.store_id = l2.store_id AND sr.product_id = l2.product_id'
                .' WHERE l2.store_id = ?) AS r'
                .' WHERE l.store_id = r.store_id AND l.locale = r.locale AND l.product_id = r.product_id AND l.sales_rank IS DISTINCT FROM r.rank',
                [$store->value],
            );
        });
    }

    public function ratings(array $ratings): void
    {
        $rows = [];
        $now = CarbonImmutable::now();

        foreach ($ratings as $productId => $rating) {
            $id = strtolower(trim((string) $productId));

            if (Ulids::valid($id)) {
                $rows[$id] = ['product_id' => $id, 'rating_sum' => $rating['sum'], 'rating_count' => $rating['count'], 'updated_at' => $now];
            }
        }

        if ($rows === []) {
            return;
        }

        // Inside the caller's transaction: the products' lock first, as every change the shop's lists
        // read takes it — which also replaces the cached pages' version (DatabaseListLocks).
        $this->db->transaction(function () use ($rows): void {
            $this->locks->lock(ListLocks::PRODUCTS);
            $known = $this->db->table('catalog.products')->whereIn('id', array_keys($rows))->pluck('id')->map(strval(...))->all();
            $kept = array_values(array_intersect_key($rows, array_flip($known)));

            if ($kept !== []) {
                $this->db->table('catalog.product_ratings')->upsert($kept, ['product_id'], ['rating_sum', 'rating_count', 'updated_at']);
            }
        });
    }

    public function ratingsShown(StoreId $store, bool $shown): void
    {
        $this->db->transaction(function () use ($store, $shown): void {
            $this->locks->lock(ListLocks::PRODUCTS);
            $this->db->table('catalog.store_rating_display')->upsert(
                [['store_id' => $store->value, 'shown' => $shown, 'updated_at' => CarbonImmutable::now()]],
                ['store_id'],
                ['shown', 'updated_at'],
            );
        });
    }

    /**
     * @param  array<array-key, array<string, bool|int|string|null>>  $facts  variant id => its columns
     */
    private function write(StoreId $store, array $facts): void
    {
        $byId = [];

        foreach ($facts as $variantId => $columns) {
            $id = strtolower(trim((string) $variantId));

            if (Ulids::valid($id)) {
                $byId[$id] = $columns;
            }
        }

        if ($byId === []) {
            return;
        }

        // Inside the caller's transaction, or a transaction of its own: the products' lock first, as
        // every change that writes the listing takes it (ListLocks::PRODUCTS).
        $this->db->transaction(function () use ($store, $byId): void {
            $this->locks->lock(ListLocks::PRODUCTS);

            $products = [];

            foreach ($this->db->table('catalog.variants')->whereIn('id', array_keys($byId))->get(['id', 'product_id']) as $row) {
                $products[(string) $row->id] = (string) $row->product_id;
            }

            if ($products === []) {
                return;
            }

            $known = array_keys($products);
            $now = CarbonImmutable::now();
            $rows = array_map(static fn (string $id): array => ['store_id' => $store->value, 'variant_id' => $id, ...$byId[$id], 'created_at' => $now, 'updated_at' => $now], $known);

            // One shape of columns a call: every fact pushed in one call is the same kind.
            $this->db->table(self::TABLE)->upsert($rows, ['store_id', 'variant_id'], [...array_keys($byId[$known[0]]), 'updated_at']);

            // The cards read them from the listing (P22, amendment 16(h)): written again at once, so
            // a list is never stale (§2.2).
            $this->listingRows->refresh(array_values(array_unique($products)));
        });
    }
}
