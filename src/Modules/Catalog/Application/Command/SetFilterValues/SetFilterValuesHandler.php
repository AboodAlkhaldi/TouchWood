<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetFilterValues;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\ProductParts;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\ProductArchived;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **A product's filter values** (catalog.md amendment 3(a)): `catalog.product.update`, as its shared
 * data. Values of filter attributes, set on the product for all its variants, several of one
 * attribute allowed; each value's row is locked, as a value's deletion locks it before asking whether
 * anything uses it.
 */
final readonly class SetFilterValuesHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private ProductParts $parts,
        private Readiness $readiness,
        private ProductEvents $events,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|ProductArchived|ProductNotFound|TooMany|Unauthorized
     */
    public function handle(SetFilterValues $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->readiness->requireNotArchived($product);
            $before = $this->products->filterValues($product->id());
            $after = $this->parts->filterValues($command->valueIds, $before);
            $sortedBefore = array_keys($before);
            $sortedAfter = array_keys($after);
            sort($sortedBefore);
            sort($sortedAfter);

            if ($sortedAfter === $sortedBefore) {
                return [null, []];
            }

            $this->products->replaceFilterValues($product->id(), $after);
            $this->events->changed($product->id());

            return [null, [ListAudit::replaced('product', 'filter_values_changed', $product->id(), 'value_ids', implode(',', $sortedBefore) ?: null, implode(',', $sortedAfter) ?: null)]];
        });
    }
}
