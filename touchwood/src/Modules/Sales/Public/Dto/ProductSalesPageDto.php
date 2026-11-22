<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

/** A page of `SalesReports::productSales`, and the cursor of the page after it (none on the last). */
final readonly class ProductSalesPageDto
{
    /**
     * @param  list<ProductSaleDto>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
    ) {}
}
