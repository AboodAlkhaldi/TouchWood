<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditAttributeSet;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SetMembers;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Model\AttributeSet;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Shared\Application\Unauthorized;

/**
 * **Editing an attribute set** (catalog.md §1.7): its name, its members and their order. "A
 * product's set cannot change once it has variants" (§9.3 #13) and what editing a set some product
 * already takes means for its variants join with the products (step 3).
 */
final readonly class EditAttributeSetHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
        private SetMembers $members,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|Unauthorized
     */
    public function handle(EditAttributeSet $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $name = LocalizedName::of($command->nameAr, $command->nameEn, AttributeSet::NAME_MAX);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command, $name): array {
            $set = $this->attributes->setById($command->setId) ?? throw new ListItemNotFound($command->setId);
            $set->edit($name, $this->members->read($command->attributeIds));
            $entry = ListAudit::changed('attribute_set', 'edited', $set->id(), $set->pullChanges(), $set->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->attributes->updateSet($set);

            return [null, [$entry]];
        });
    }
}
