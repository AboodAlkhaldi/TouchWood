<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\MakeBrandDefault;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Making a brand the default** (catalog.md §1.6, owner 2026-10-02: "movable, always exactly one"):
 * the old default is un-marked in the same step, so there is never none and never two. Only an
 * active brand can be the default.
 */
final readonly class MakeBrandDefaultHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private BrandRepository $brands,
    ) {}

    /**
     * @throws BrandInactive|BrandNotFound|Unauthorized
     */
    public function handle(MakeBrandDefault $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::BRANDS, function () use ($command): array {
            $brand = $this->brands->byId($command->brandId) ?? throw new BrandNotFound($command->brandId);

            if ($brand->isDefault()) {
                return [null, []];
            }

            if (! $brand->isActive()) {
                throw new BrandInactive;
            }

            $entries = [];
            $old = $this->brands->defaultBrand();

            // The old mark goes first: the database keeps at most one default at every moment.
            if ($old !== null) {
                $old->unmarkDefault();
                $this->brands->update($old);
                $entries[] = ListAudit::changed('brand', 'default_moved', $old->id(), $old->pullChanges(), $old->snapshot());
            }

            $brand->makeDefault();
            $this->brands->update($brand);
            $entries[] = ListAudit::changed('brand', 'made_default', $brand->id(), $brand->pullChanges(), $brand->snapshot());

            return [null, array_values(array_filter($entries))];
        });
    }
}
