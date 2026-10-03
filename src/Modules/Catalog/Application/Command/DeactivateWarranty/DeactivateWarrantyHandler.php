<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateWarranty;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Shared\Application\Unauthorized;

/**
 * **Deactivating a warranty** (catalog.md §1.9, §9.3 #14):
 * no longer offered for new use, and back by activating it; what already carries it keeps it.
 * Deleting is for what nothing uses.
 */
final readonly class DeactivateWarrantyHandler
{
    public const string PERMISSION = CatalogPermissions::WARRANTY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private WarrantyRepository $warranties,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(DeactivateWarranty $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::WARRANTIES, function () use ($command): array {
            $warranty = $this->warranties->byId($command->warrantyId) ?? throw new ListItemNotFound($command->warrantyId);
            $warranty->deactivate();
            $entry = ListAudit::changed('warranty', 'deactivated', $warranty->id(), $warranty->pullChanges(), $warranty->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->warranties->update($warranty);

            return [null, [$entry]];
        });
    }
}
