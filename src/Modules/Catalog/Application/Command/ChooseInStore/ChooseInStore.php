<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ChooseInStore;

/**
 * A store switches a product on or off — the whole product, or the variants named.
 */
final readonly class ChooseInStore
{
    /**
     * @param  array<array-key, mixed>|null  $variantIds  null for the whole product
     */
    public function __construct(
        public string $storeId,
        public string $productId,
        public bool $active,
        public ?array $variantIds = null,
    ) {}
}
