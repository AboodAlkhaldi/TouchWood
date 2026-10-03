<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddWarranty;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Lists\WarrantyInput;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Model\Warranty;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Shared\Application\Unauthorized;

/**
 * **Adding a warranty** to the one list (catalog.md §1.9), under `catalog.warranty.manage` with All
 * stores: a product carries at most one, the same in every store.
 */
final readonly class AddWarrantyHandler
{
    public const string PERMISSION = CatalogPermissions::WARRANTY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private WarrantyRepository $warranties,
    ) {}

    /**
     * @return string the new warranty's id
     *
     * @throws InvalidCatalogAttribute|Unauthorized
     */
    public function handle(AddWarranty $command): string
    {
        $this->change->authorize(self::PERMISSION);
        $input = WarrantyInput::of($command->nameAr, $command->nameEn, $command->termsAr, $command->termsEn, $command->periodMonths);
        $warranty = Warranty::add($this->warranties->nextId(), $input->name, $input->termsAr, $input->termsEn, $input->period);

        return $this->change->run(ListLocks::WARRANTIES, function () use ($warranty): array {
            $this->warranties->add($warranty);

            return [$warranty->id(), [ListAudit::added('warranty', $warranty->id(), $warranty->snapshot())]];
        });
    }
}
