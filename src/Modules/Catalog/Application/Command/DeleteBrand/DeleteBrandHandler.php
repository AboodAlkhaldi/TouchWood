<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteBrand;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\BrandInUse;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\DefaultBrandRequired;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Deleting a brand** (catalog.md §1.6): only one nothing uses, and never the default (§9.3 #12).
 * Its slugs go with it. **Never one a product carries, archived ones included** (`BrandInUse`):
 * asked after the brand's row is locked, which a product's change locks too before taking it.
 */
final readonly class DeleteBrandHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private BrandRepository $brands,
        private ProductRepository $products,
    ) {}

    /**
     * @throws BrandInUse|BrandNotFound|DefaultBrandRequired|Unauthorized
     */
    public function handle(DeleteBrand $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::BRANDS, function () use ($command): array {
            $brand = $this->brands->byId($command->brandId) ?? throw new BrandNotFound($command->brandId);

            if ($brand->isDefault()) {
                throw new DefaultBrandRequired;
            }

            if ($this->products->anyWithBrand($brand->id())) {
                throw new BrandInUse;
            }

            $was = $brand->snapshot();
            $this->brands->delete($brand->id());

            return [null, [ListAudit::deleted('brand', $brand->id(), $was)]];
        });
    }
}
