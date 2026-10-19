<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\External;

use Modules\Sales\Public\Contracts\SalesApi;
use Modules\Sales\Public\Dto\DeliveredPurchaseDto;
use Modules\Sales\Public\Dto\ReturnedProductsDto;

/**
 * `SalesApi` until Sales is built (feedback.md §2.1): no order exists yet, so nothing was delivered
 * and no return completed — a review is refused as no verified purchase. Replaced by Sales's own
 * implementation in its build.
 */
final readonly class NothingSoldYet implements SalesApi
{
    public function deliveredPurchase(string $customerId, string $productId): ?DeliveredPurchaseDto
    {
        return null;
    }

    public function returnedProducts(string $returnId): ?ReturnedProductsDto
    {
        return null;
    }
}
