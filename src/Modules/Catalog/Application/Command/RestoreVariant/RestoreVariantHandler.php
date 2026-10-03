<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RestoreVariant;

use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\ProductArchived;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Shared\Application\Unauthorized;

/**
 * **Restoring an archived variant** (catalog.md §1.2): `catalog.product.update`, as its product's
 * shared data. It is chosen again in no store until each store chooses it (§1.3).
 */
final readonly class RestoreVariantHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private VariantRepository $variants,
        private Readiness $readiness,
        private ProductEvents $events,
    ) {}

    /**
     * @throws ProductArchived|Unauthorized|VariantNotFound
     */
    public function handle(RestoreVariant $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $variant = $this->variants->byId($command->variantId) ?? throw new VariantNotFound($command->variantId);
            $product = $this->products->byId($variant->productId()) ?? throw new LogicException('A variant without its product.');
            $this->readiness->requireNotArchived($product);
            $variant->restore();
            $entry = ListAudit::changed('variant', 'restored', $variant->id(), $variant->pullChanges(), $variant->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->variants->updateRow($variant);
            $this->events->variantRestored($product->id(), $variant->id());

            return [null, [$entry]];
        });
    }
}
