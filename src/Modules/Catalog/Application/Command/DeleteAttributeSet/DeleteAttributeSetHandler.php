<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteAttributeSet;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemInUse;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Deleting an attribute set** (catalog.md §1.7, §9.3 #14); its attributes stay in the library.
 * **Never one a product takes** (`ListItemInUse`), asked after the set's row is locked.
 */
final readonly class DeleteAttributeSetHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
        private ProductRepository $products,
    ) {}

    /**
     * @throws ListItemInUse|ListItemNotFound|Unauthorized
     */
    public function handle(DeleteAttributeSet $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command): array {
            $set = $this->attributes->setById($command->setId) ?? throw new ListItemNotFound($command->setId);

            if ($this->products->anyWithAttributeSet($set->id())) {
                throw new ListItemInUse;
            }

            $was = $set->snapshot();
            $this->attributes->deleteSet($set->id());

            return [null, [ListAudit::deleted('attribute_set', $set->id(), $was)]];
        });
    }
}
