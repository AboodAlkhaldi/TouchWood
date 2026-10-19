<?php

declare(strict_types=1);

namespace Tests\Modules\Feedback\Support;

use Modules\Sales\Public\Contracts\SalesApi;
use Modules\Sales\Public\Dto\DeliveredPurchaseDto;
use Modules\Sales\Public\Dto\ReturnedProductsDto;

/**
 * **Sales does not exist yet** (feedback.md §2.1): its contract is declared and faked here — what was
 * delivered to whom, and which returns completed — exactly as `SalesApi` promises to answer.
 */
final class FakeSales implements SalesApi
{
    /** @var array<string, DeliveredPurchaseDto> by "customer product" */
    private array $delivered = [];

    /** @var array<string, ReturnedProductsDto> by return id */
    private array $returns = [];

    public function deliver(string $customerId, string $productId, DeliveredPurchaseDto $purchase): void
    {
        $this->delivered["{$customerId} {$productId}"] = $purchase;
    }

    public function complete(string $returnId, ReturnedProductsDto $return): void
    {
        $this->returns[$returnId] = $return;
    }

    public function deliveredPurchase(string $customerId, string $productId): ?DeliveredPurchaseDto
    {
        return $this->delivered["{$customerId} {$productId}"] ?? null;
    }

    public function returnedProducts(string $returnId): ?ReturnedProductsDto
    {
        return $this->returns[$returnId] ?? null;
    }
}
