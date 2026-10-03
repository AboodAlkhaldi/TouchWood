<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateBrand;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Activating a brand again** (catalog.md §1.6): offered on the form, the filter and its page again.
 */
final readonly class ActivateBrandHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private BrandRepository $brands,
    ) {}

    /**
     * @throws BrandNotFound|Unauthorized
     */
    public function handle(ActivateBrand $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::BRANDS, function () use ($command): array {
            $brand = $this->brands->byId($command->brandId) ?? throw new BrandNotFound($command->brandId);
            $brand->activate();
            $entry = ListAudit::changed('brand', 'activated', $brand->id(), $brand->pullChanges(), $brand->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->brands->update($brand);

            return [null, [$entry]];
        });
    }
}
