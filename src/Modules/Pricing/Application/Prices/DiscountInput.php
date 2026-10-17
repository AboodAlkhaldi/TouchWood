<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\ValueObject\DiscountLines;

/**
 * A category discount's lines and the products it leaves out, as callers write them (pricing.md §1.5).
 */
final readonly class DiscountInput
{
    public function __construct(
        private CatalogApi $catalog,
    ) {}

    /**
     * @throws InvalidPricingInput
     */
    public static function lines(string $lines): DiscountLines
    {
        return DiscountLines::tryFrom(strtoupper(trim($lines))) ?? throw new InvalidPricingInput('lines');
    }

    /**
     * @param  list<string>  $productIds
     * @return list<string> each product's own id, once, in id order
     *
     * @throws InvalidPricingInput a product that does not exist
     */
    public function leftOut(array $productIds): array
    {
        $own = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $productIds)));
        $found = $this->catalog->products($own);

        foreach ($own as $id) {
            if (! isset($found[$id])) {
                throw new InvalidPricingInput('product');
            }
        }

        sort($own);

        return $own;
    }
}
