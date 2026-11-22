<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditWarranty;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Lists\WarrantyInput;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Shared\Application\Unauthorized;

/**
 * **Editing a warranty** (catalog.md §1.9) — and so on every product that carries it.
 */
final readonly class EditWarrantyHandler
{
    public const string PERMISSION = CatalogPermissions::WARRANTY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private WarrantyRepository $warranties,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(EditWarranty $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $input = WarrantyInput::of($command->nameAr, $command->nameEn, $command->termsAr, $command->termsEn, $command->periodMonths);

        $this->change->run(ListLocks::WARRANTIES, function () use ($command, $input): array {
            $warranty = $this->warranties->byId($command->warrantyId) ?? throw new ListItemNotFound($command->warrantyId);
            $warranty->edit($input->name, $input->termsAr, $input->termsEn, $input->period);
            $entry = ListAudit::changed('warranty', 'edited', $warranty->id(), $warranty->pullChanges(), $warranty->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->warranties->update($warranty);

            return [null, [$entry]];
        });
    }
}
