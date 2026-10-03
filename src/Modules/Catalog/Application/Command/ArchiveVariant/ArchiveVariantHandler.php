<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ArchiveVariant;

use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\ProductArchived;
use Modules\Catalog\Domain\Exception\ProductNotReady;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Model\Variant;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Shared\Application\Unauthorized;

/**
 * **Archiving a variant on its own** (catalog.md §1.2): `catalog.product.update`, as its product's
 * shared data — a discontinued length. It becomes Inactive in every store (step 4); its code stays its
 * product's; a ready product keeps at least one variant not archived (`ProductNotReady`).
 */
final readonly class ArchiveVariantHandler
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
     * @throws ProductArchived|ProductNotReady|Unauthorized|VariantNotFound
     */
    public function handle(ArchiveVariant $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $variant = $this->variants->byId($command->variantId) ?? throw new VariantNotFound($command->variantId);
            $product = $this->products->byId($variant->productId()) ?? throw new LogicException('A variant without its product.');
            $this->readiness->requireNotArchived($product);
            $variant->archive();
            $entry = ListAudit::changed('variant', 'archived', $variant->id(), $variant->pullChanges(), $variant->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            // A ready product keeps a variant that is not archived (§1.1).
            $this->readiness->requireKept($product, variants: array_map(
                static fn (Variant $sibling): Variant => $sibling->id() === $variant->id() ? $variant : $sibling,
                $this->variants->ofProduct($product->id()),
            ));
            $this->variants->update($variant);
            $this->events->variantArchived($product->id(), $variant->id());

            return [null, [$entry]];
        });
    }
}
