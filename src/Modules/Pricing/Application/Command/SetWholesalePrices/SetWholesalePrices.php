<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\SetWholesalePrices;

/**
 * A size's wholesale bands in a store, all at once (pricing.md §1.4, §3): from each quantity, the price
 * as typed - e.g. [20 => "85", 100 => "78"]. None removes them.
 */
final readonly class SetWholesalePrices
{
    /**
     * @param  array<int|string, string>  $bands  from quantity => the price as typed
     */
    public function __construct(
        public string $storeId,
        public string $variantId,
        public array $bands,
    ) {}
}
