<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetVariantPhotos;

use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\ProductParts;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Shared\Application\Unauthorized;

/**
 * **A variant's own photos** (catalog.md §1.2, §9.3 #6): `catalog.product.update`, as its product's
 * shared data — at most 10 public images, each once. They hang off the variant, not its code, so a
 * corrected code leaves them in place.
 */
final readonly class SetVariantPhotosHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public const int MAX = 10;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private VariantRepository $variants,
        private ProductRepository $products,
        private ProductEvents $events,
        private ProductParts $parts,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|TooMany|Unauthorized|VariantNotFound
     */
    public function handle(SetVariantPhotos $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $variant = $this->variants->byId($command->variantId) ?? throw new VariantNotFound($command->variantId);
            // A variant never outlives its product (the key cascades), so this always finds it.
            $product = $this->products->byId($variant->productId()) ?? throw new LogicException('A variant without its product.');
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $before = $this->variants->photos($variant->id());
            $after = $this->parts->photos('photos', $command->mediaIds, self::MAX);

            if ($after === $before) {
                return [null, []];
            }

            $this->variants->replacePhotos($variant->id(), $after);
            $this->events->changed($product);

            return [null, [ListAudit::replaced('variant', 'photos_changed', $variant->id(), 'media_ids', implode(',', $before) ?: null, implode(',', $after) ?: null)]];
        });
    }
}
