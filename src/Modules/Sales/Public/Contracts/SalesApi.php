<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Contracts;

use Modules\Sales\Public\Dto\DeliveredPurchaseDto;
use Modules\Sales\Public\Dto\ReturnedProductsDto;

/**
 * What other modules ask Sales (sales.md §2.1) — **declared before Sales is built**, so Feedback can be
 * built against it (feedback.md §2.1, as stage 5's interfaces were, #97). Until Sales exists a
 * placeholder answers: nothing delivered, no return.
 */
interface SalesApi
{
    /**
     * Feedback's verified purchase: whether the customer had the product delivered, in any store —
     * "delivered" per order line, by the shipment holding that line reaching delivered — with the
     * variant delivered last, when, and whether any of that product came back in a completed return.
     * Gift lines never count (feedback.md §9.1b, 20). Null: never delivered.
     */
    public function deliveredPurchase(string $customerId, string $productId): ?DeliveredPurchaseDto;

    /**
     * A completed return's customer, store and the products it took back — only those with pieces
     * actually received, gift lines never (feedback.md §9.1b, 20). Null for a return that is not
     * completed, or unknown.
     */
    public function returnedProducts(string $returnId): ?ReturnedProductsDto;
}
