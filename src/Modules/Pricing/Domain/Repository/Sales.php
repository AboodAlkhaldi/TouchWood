<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

use DateTimeImmutable;
use Modules\Pricing\Domain\ValueObject\Sale;
use Modules\Pricing\Domain\ValueObject\WindowOrder;
use Modules\Pricing\Domain\ValueObject\WindowState;
use Shared\Domain\ValueObject\StoreId;

/**
 * Sales and the sizes each covers (pricing.md §5 `pricing.sales`, `pricing.sale_variants`). Every query
 * names its store, but `find`, which finds the sale's store with it.
 */
interface Sales
{
    public function find(string $saleId): ?Sale;

    /** The store a sale is in, read alone - to ask the reader's permission there before reading the sale. */
    public function storeOf(string $saleId): ?string;

    /**
     * @param  list<string>  $saleIds
     * @return array<string, Sale> by id; one that does not exist is left out
     */
    public function findMany(array $saleIds): array;

    /** A new sale or a changed one, with its sizes. */
    public function save(Sale $sale): void;

    public function remove(string $saleId): void;

    /**
     * @param  list<string>  $variantIds
     * @return list<Sale> every sale on any of these sizes not ended by then
     */
    public function notEndedOn(StoreId $store, array $variantIds, DateTimeImmutable $at): array;

    /**
     * @return list<Sale> every sale in the store not ended by then
     */
    public function notEnded(StoreId $store, DateTimeImmutable $at): array;

    /**
     * A page of the store's sales for the screens (`ListSales`, pricing.md §4.3.3 P2): in one state, of one
     * product, or the one sale named - in the order chosen (`WindowOrder`), keyset-paged after the keys of
     * the page before - with how many match and the keys of its last row when more follow.
     *
     * @param  array{int, string, string}|null  $after
     * @return array{list<Sale>, int, array{int, string, string}|null}
     */
    public function list(StoreId $store, DateTimeImmutable $now, ?WindowState $state, ?string $productId, ?string $saleId, WindowOrder $order, ?array $after, int $limit): array;

    /**
     * @return list<string> the products with a sale in the store, any state - P2's product filter
     */
    public function productIds(StoreId $store): array;

    /**
     * @param  list<StoreId>  $stores
     * @return list<Sale> the product's sales not ended by then, in these stores
     */
    public function notEndedOfProduct(array $stores, string $productId, DateTimeImmutable $at): array;

    /**
     * The daily safety check's read (§1.10), the one that names no store: every sale, in any store, whose
     * start or end fell after `$from` and by `$to`.
     *
     * @return list<Sale>
     */
    public function windowsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
