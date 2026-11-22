<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\NoRetailPrice;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Shared\Domain\ValueObject\StoreId;

/**
 * The sizes a sale covers (pricing.md §1.3), as adding one and the note before saving it read them:
 *
 * - **its product ready and switched on in the store** (owner Q13, 2026-10-19) - never a draft, an archived
 *   product, or one the store has not switched on;
 * - the sizes named - one, or some of one product's (owner, 2026-10-13), never two products' - each
 *   switched on in the store, none archived (read-only until restored, §7; owner, 2026-10-11);
 * - or, with none named, **"All Sizes": every size of the product switched on in the store that has a price
 *   there**, saved as that list (owner Q39, 2026-10-19) - so one size with no price no longer refuses the
 *   sale; a product with none priced is refused as having no retail price.
 *
 * A few reads however many sizes: the sizes and their products in two (`SizeInput`), the store's
 * switched-on sizes in one.
 */
final readonly class SaleSizes
{
    public function __construct(
        private CatalogApi $catalog,
        private SizeInput $sizes,
        private RetailPrices $retailPrices,
    ) {}

    /**
     * @param  list<string>  $variantIds
     * @return array{string, list<string>} the product, and the sizes in size-id order
     *
     * @throws InvalidPricingInput|NoRetailPrice
     */
    public function of(StoreId $store, ?string $productId, array $variantIds): array
    {
        if ($variantIds === []) {
            $product = $productId === null ? null : $this->catalog->product($productId);

            if ($product === null || $product->stage !== ProductStage::Ready) {
                throw new InvalidPricingInput('product');
            }

            $switchedOn = array_values(array_intersect($this->catalog->variantIdsOf($product->id), $this->catalog->switchedOnVariantIds($store)));

            if ($switchedOn === []) {
                throw new InvalidPricingInput('product');
            }

            $variantIds = array_values(array_intersect($switchedOn, array_keys($this->retailPrices->forVariants($store, $switchedOn))));

            if ($variantIds === []) {
                sort($switchedOn);

                throw new NoRetailPrice($switchedOn);
            }

            sort($variantIds);

            return [$product->id, $variantIds];
        }

        [$own, $archived, $notFound] = $this->sizes->sort($variantIds);

        if ($archived !== [] || $notFound !== []) {
            throw new InvalidPricingInput('variant', [...$archived, ...$notFound]);
        }

        $sizes = array_values(array_unique(array_map(static fn (array $size): string => $size[0], $own)));
        $products = array_values(array_unique(array_map(static fn (array $size): string => $size[1], $own)));

        if (count($products) !== 1 || ($productId !== null && strtolower(trim($productId)) !== $products[0])) {
            throw new InvalidPricingInput('variant', $sizes);
        }

        // Neither archived (left out above) nor a draft: ready (Q13).
        foreach ($own as [, , $draft]) {
            if ($draft) {
                throw new InvalidPricingInput('product');
            }
        }

        $off = array_values(array_diff($sizes, $this->catalog->switchedOnVariantIds($store)));

        if ($off !== []) {
            sort($off);

            throw new InvalidPricingInput('variant', $off);
        }

        sort($sizes);

        return [$products[0], $sizes];
    }
}
