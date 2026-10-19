<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

/** A completed return (sales.md §2.1): its customer, its store, and the products it took back. */
final readonly class ReturnedProductsDto
{
    /**
     * @param  list<string>  $productIds
     */
    public function __construct(
        public string $customerId,
        public string $storeId,
        public array $productIds,
    ) {}
}
