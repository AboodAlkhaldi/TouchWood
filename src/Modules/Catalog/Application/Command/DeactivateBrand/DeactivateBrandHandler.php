<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateBrand;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\DefaultBrandRequired;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Deactivating a brand** (catalog.md §1.6): it leaves the product form's choices, the brand filter
 * and its page; the default brand is refused (§9.3 #12). Choosing each of its products' fate — hide
 * it with the brand, or move it to another — arrives with the products (step 4).
 */
final readonly class DeactivateBrandHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private BrandRepository $brands,
    ) {}

    /**
     * @throws BrandNotFound|DefaultBrandRequired|Unauthorized
     */
    public function handle(DeactivateBrand $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::BRANDS, function () use ($command): array {
            $brand = $this->brands->byId($command->brandId) ?? throw new BrandNotFound($command->brandId);
            $brand->deactivate();
            $entry = ListAudit::changed('brand', 'deactivated', $brand->id(), $brand->pullChanges(), $brand->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->brands->update($brand);

            return [null, [$entry]];
        });
    }
}
