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
     * The products left out: each must exist and be in the category or one below it (owner Q10,
     * 2026-10-19) - or be left out already, so a discount whose left-out product moved away since can still
     * have its end changed.
     *
     * @param  list<string>  $productIds
     * @param  list<string>  $alreadyLeftOut  the discount's own, when it is being changed
     * @return list<string> each product's own id, once, in id order
     *
     * @throws InvalidPricingInput a product that does not exist (`product`), or one outside the category (`left_out`)
     */
    public function leftOut(array $productIds, string $categoryId, array $alreadyLeftOut = []): array
    {
        $own = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $productIds)));

        if ($own === []) {
            return [];
        }

        $found = $this->catalog->products($own);
        $inCategory = array_fill_keys([...$this->catalog->productIdsInCategory(strtolower(trim($categoryId))), ...$alreadyLeftOut], true);

        foreach ($own as $id) {
            if (! isset($found[$id])) {
                throw new InvalidPricingInput('product');
            }

            if (! isset($inCategory[$id])) {
                throw new InvalidPricingInput('left_out');
            }
        }

        sort($own);

        return $own;
    }
}
