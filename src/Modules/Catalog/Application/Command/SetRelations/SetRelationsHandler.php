<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetRelations;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\ProductParts;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **A product's hand-picked relations** (catalog.md §1.10, amendment 3(d)): `catalog.product.update`,
 * as its shared data. "Related" fills the product page's "You may also like" (filled in by itself
 * when none is picked — the listing, step 5); "Goes with" its accessories. Ready products only, at
 * most 20 in each list, never the product itself.
 */
final readonly class SetRelationsHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public const array KINDS = ['RELATED' => 'related', 'GOES_WITH' => 'goes_with'];

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private ProductParts $parts,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ProductNotFound|TooMany|Unauthorized
     */
    public function handle(SetRelations $command): void
    {
        $this->access->authorize(self::PERMISSION);
        $column = self::KINDS[$command->kind] ?? throw new InvalidCatalogAttribute('kind', 'related or goes with');

        $this->change->run(ListLocks::PRODUCTS, function () use ($command, $column): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $before = $this->products->relations($product->id(), $command->kind);
            $after = $this->parts->relations($product->id(), $command->productIds, $before);

            if ($after === $before) {
                return [null, []];
            }

            $this->products->replaceRelations($product->id(), $command->kind, $after);

            return [null, [ListAudit::replaced('product', 'relations_changed', $product->id(), $column, implode(',', $before) ?: null, implode(',', $after) ?: null)]];
        });
    }
}
