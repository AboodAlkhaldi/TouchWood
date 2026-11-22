<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\SetRetailPrice;

/**
 * Set the retail price of one size or several in a store (pricing.md §1.2, §3).
 */
final readonly class SetRetailPrice
{
    /**
     * @param  array<string, string>  $prices  variant id => the price as typed, e.g. "149.50"
     */
    public function __construct(
        public string $storeId,
        public array $prices,
    ) {}
}
