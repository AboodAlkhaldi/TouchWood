<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;

/**
 * The sizes a sale covers (pricing.md §1.3), as adding one and the note before saving it read them: the
 * sizes named - one, or some of one product's (owner, 2026-10-13), never two products' - or, with none
 * named, every size of the product as it is now; archived ones left out, and none of an archived
 * product, read-only until it is restored (§7; owner, 2026-10-11).
 */
final readonly class SaleSizes
{
    public function __construct(
        private CatalogApi $catalog,
        private SizeInput $sizes,
    ) {}

    /**
     * @param  list<string>  $variantIds
     * @return array{string, list<string>} the product, and the sizes in size-id order
     *
     * @throws InvalidPricingInput
     */
    public function of(?string $productId, array $variantIds): array
    {
        if ($variantIds === []) {
            $product = $productId === null ? null : $this->catalog->product($productId);
            $variantIds = $product === null || $product->stage === ProductStage::Archived ? [] : $this->catalog->variantIdsOf($product->id);

            if ($product === null || $variantIds === []) {
                throw new InvalidPricingInput('product');
            }
        } else {
            $variantIds = array_values(array_unique($this->sizes->check($variantIds)));
            $products = array_values(array_unique(array_map(fn (string $id): string => (string) $this->catalog->variant($id)?->productId, $variantIds)));

            if (count($products) !== 1 || ($productId !== null && strtolower(trim($productId)) !== $products[0])) {
                throw new InvalidPricingInput('variant');
            }

            $product = $this->catalog->product($products[0]) ?? throw new InvalidPricingInput('product');
        }

        sort($variantIds);

        return [$product->id, $variantIds];
    }
}
