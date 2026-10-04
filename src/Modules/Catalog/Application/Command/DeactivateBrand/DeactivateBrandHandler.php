<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateBrand;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\ProductFates;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductReferences;
use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\DefaultBrandRequired;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Public\Enums\ProductFate;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **Deactivating a brand** (catalog.md §1.6): it leaves the product form's choices, the brand filter
 * and its page; the default brand is refused (§9.3 #12). **Each of its products, in any stage, has
 * its fate** — its own, or the one for all: **hidden** with the brand, or **moved** to another active
 * brand; never left without one. One step, all of it or none of it, each change audited. It changes
 * products, so it takes the products' lock before the brands'.
 */
final readonly class DeactivateBrandHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private BrandRepository $brands,
        private ProductRepository $products,
        private ProductReferences $references,
        private ProductEvents $events,
    ) {}

    /**
     * @throws BrandInactive|BrandNotFound|DefaultBrandRequired|InvalidCatalogAttribute|Unauthorized
     */
    public function handle(DeactivateBrand $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $fates = ProductFates::of($command->everyProduct, $command->moveTo, $command->products, [ProductFate::Hide, ProductFate::Move]);

        $this->change->runAfterProducts(ListLocks::BRANDS, function () use ($command, $fates): array {
            $brand = $this->brands->byId($command->brandId) ?? throw new BrandNotFound($command->brandId);
            $brand->deactivate();
            $entry = ListAudit::changed('brand', 'deactivated', $brand->id(), $brand->pullChanges(), $brand->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->brands->update($brand);
            $entries = [$entry];
            $reached = $this->products->idsWithBrand($brand->id());
            $fates->requireWithin($reached);

            foreach ($reached as $productId) {
                $settled = $this->settle($productId, $fates);

                if ($settled !== null) {
                    $entries[] = $settled;
                }
            }

            return [null, $entries];
        });
    }

    /**
     * One product's fate. Run after the brand went, so a move to it finds it inactive.
     *
     * @throws BrandInactive|BrandNotFound|InvalidCatalogAttribute
     */
    private function settle(string $productId, ProductFates $fates): ?AuditEntryDto
    {
        [$fate, $moveTo] = $fates->for($productId);
        // Read under the products' lock, which this change holds.
        $product = $this->products->byId($productId);

        if ($product === null) {
            return null;
        }

        if ($fate === ProductFate::Hide) {
            $product->hideWithBrand(true);
        } else {
            $product->moveToBrand($this->references->brand($moveTo === null || trim($moveTo) === '' ? throw new InvalidCatalogAttribute('move_to', 'where to move it') : $moveTo));
        }

        // Each action written out, so the audit log's names can be checked against the code.
        $entry = $fate === ProductFate::Hide
            ? ListAudit::changed('product', 'hidden', $product->id(), $product->pullChanges(), $product->snapshot())
            : ListAudit::changed('product', 'moved', $product->id(), $product->pullChanges(), $product->snapshot());

        if ($entry !== null) {
            $this->products->update($product);
            $this->events->changed($product);
        }

        return $entry;
    }
}
