<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * **A product deleted whole** (catalog.md §4.1): its variants, slugs, codes, photos' links, search
 * words, filter values and its own links go with it by their foreign keys, its slugs and codes free
 * again. Who may, and which product may go, is the caller's to ask: a draft through
 * `DeleteDraftProduct`; one the import's page deletes whatever was done to it since (amendment 11(c)),
 * taken off sale and unlinked first. Called inside the caller's transaction, under the products' lock.
 */
final readonly class ProductDeletion
{
    public function __construct(
        private ProductRepository $products,
        private VariantRepository $variants,
    ) {}

    /**
     * @return list<AuditEntryDto> what went, for the caller to record in its transaction
     */
    public function delete(Product $product): array
    {
        $entries = [];

        foreach ($this->variants->ofProduct($product->id()) as $variant) {
            $entries[] = ListAudit::deleted('variant', $variant->id(), ['product_id' => $product->id(), ...$variant->snapshot()]);
        }

        $entries[] = ListAudit::deleted('product', $product->id(), [...$product->snapshot(), 'codes' => implode(',', $this->products->codesOf($product->id())) ?: null]);
        $this->products->delete($product->id());

        return $entries;
    }
}
