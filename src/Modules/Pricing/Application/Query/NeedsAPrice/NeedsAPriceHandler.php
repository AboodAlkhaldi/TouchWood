<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\NeedsAPrice;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\Prices\UnpricedSizes;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Shared\Application\Unauthorized;

/**
 * **Needs a Price** (pricing.md §1.9, §3), under **Edit Prices and Sales** in that store (owner,
 * 2026-10-08): every size switched on with no retail price, named by Catalog's sizes (a read per 1,000)
 * and their products (one read) (§2.4). A read: no lock, nothing written.
 */
final readonly class NeedsAPriceHandler
{
    public function __construct(
        private ReadAccess $access,
        private UnpricedSizes $unpriced,
        private CatalogApi $catalog,
    ) {}

    /**
     * @return list<UnpricedSize> in size-id order
     *
     * @throws Unauthorized|InvalidPricingInput
     */
    public function handle(NeedsAPrice $query): array
    {
        $store = $this->access->authorize($query->storeId, [PricingPermissions::PRICE_EDIT])->storeId();
        $variants = [];

        // In reads of 1,000, under PostgreSQL's bind-parameter limit, however many need a price.
        foreach (array_chunk($this->unpriced->in($store), 1000) as $chunk) {
            $variants += $this->catalog->variants($chunk);
        }
        $products = $this->catalog->products(array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, array_values($variants)))));
        $rows = [];

        foreach ($variants as $variant) {
            $product = $products[$variant->productId] ?? null;

            if ($product !== null) {
                $rows[] = new UnpricedSize($variant->id, $product->id, $product->nameAr, $product->nameEn, $variant->code);
            }
        }

        usort($rows, static fn (UnpricedSize $a, UnpricedSize $b): int => $a->variantId <=> $b->variantId);

        return $rows;
    }
}
