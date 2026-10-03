<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteDraftVariant;

use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Domain\Exception\InvalidStageChange;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Shared\Application\Unauthorized;

/**
 * **Deleting a draft's variant** (catalog.md amendment 3(c)): a variant added by mistake, while the
 * product was never shown or sold. Its code, if no other variant of the draft carries it, is let go,
 * free again. Once the product is ready, a variant is archived instead (`ArchiveVariant`).
 */
final readonly class DeleteDraftVariantHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private VariantRepository $variants,
        private ProductEvents $events,
    ) {}

    /**
     * @throws InvalidStageChange|Unauthorized|VariantNotFound
     */
    public function handle(DeleteDraftVariant $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $variant = $this->variants->byId($command->variantId) ?? throw new VariantNotFound($command->variantId);
            $product = $this->products->byId($variant->productId()) ?? throw new LogicException('A variant without its product.');

            if (! $product->isDraft()) {
                throw new InvalidStageChange;
            }

            $was = ['product_id' => $product->id(), ...$variant->snapshot()];
            $this->variants->delete($variant->id());
            $this->events->changed($product->id());

            if (! $this->variants->codeInUse($product->id(), $variant->code()->value)) {
                $this->products->releaseCode($product->id(), $variant->code()->value);
            }

            return [null, [ListAudit::deleted('variant', $variant->id(), $was)]];
        });
    }
}
