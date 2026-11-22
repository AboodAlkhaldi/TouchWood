<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

/** A page of `SalesReports::productNetSales`, and the cursor of the page after it (none on the last). */
final readonly class ProductNetSalesPageDto
{
    /**
     * @param  list<ProductNetSaleDto>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
    ) {}
}
