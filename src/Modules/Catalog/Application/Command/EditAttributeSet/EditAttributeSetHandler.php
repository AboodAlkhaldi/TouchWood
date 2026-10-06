<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditAttributeSet;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SetMembers;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\AttributeSetInUse;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Model\AttributeSet;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Shared\Application\Unauthorized;

/**
 * **Editing an attribute set** (catalog.md §1.7): its name, its members and their order. **Its
 * members stay while any variant is built on it** (`AttributeSetInUse`, amendment 3(k)): every
 * variant takes one value of every attribute of its set. Its name may always change.
 */
final readonly class EditAttributeSetHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
        private SetMembers $members,
        private ProductRepository $products,
    ) {}

    /**
     * @throws AttributeSetInUse|InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|Unauthorized
     */
    public function handle(EditAttributeSet $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $name = LocalizedName::of($command->nameAr, $command->nameEn, AttributeSet::NAME_MAX);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command, $name): array {
            $set = $this->attributes->setById($command->setId) ?? throw new ListItemNotFound($command->setId);
            $members = $this->members->read($command->attributeIds);
            $before = $set->memberIds();
            $set->edit($name, $members);

            if ($set->memberIds() !== $before && $this->products->variantsOnSet($set->id())) {
                throw new AttributeSetInUse;
            }
            $entry = ListAudit::changed('attribute_set', 'edited', $set->id(), $set->pullChanges(), $set->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->attributes->updateSet($set);

            return [null, [$entry]];
        });
    }
}
