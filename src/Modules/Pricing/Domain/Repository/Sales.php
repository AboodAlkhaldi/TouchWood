<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

use DateTimeImmutable;
use Modules\Pricing\Domain\ValueObject\Sale;
use Shared\Domain\ValueObject\StoreId;

/**
 * Sales and the sizes each covers (pricing.md §5 `pricing.sales`, `pricing.sale_variants`). Every query
 * names its store, but `find`, which finds the sale's store with it.
 */
interface Sales
{
    public function find(string $saleId): ?Sale;

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
}
