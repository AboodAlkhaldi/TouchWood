<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteWarranty;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Shared\Application\Unauthorized;

/**
 * **Deleting a warranty** (catalog.md §1.9, §9.3 #14). "Carried by a product" joins the refusal with
 * the products (step 3); until then none carries one.
 */
final readonly class DeleteWarrantyHandler
{
    public const string PERMISSION = CatalogPermissions::WARRANTY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private WarrantyRepository $warranties,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(DeleteWarranty $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::WARRANTIES, function () use ($command): array {
            $warranty = $this->warranties->byId($command->warrantyId) ?? throw new ListItemNotFound($command->warrantyId);
            $was = $warranty->snapshot();
            $this->warranties->delete($warranty->id());

            return [null, [ListAudit::deleted('warranty', $warranty->id(), $was)]];
        });
    }
}
