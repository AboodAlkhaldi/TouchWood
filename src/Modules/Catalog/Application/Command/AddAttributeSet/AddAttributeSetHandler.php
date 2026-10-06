<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddAttributeSet;

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
 * **Adding an attribute set** (catalog.md §1.7): one to ten variant-making, active attributes, each
 * once, read under the attributes' lock.
 */
final readonly class AddAttributeSetHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
        private SetMembers $members,
    ) {}

    /**
     * @return string the new set's id
     *
     * @throws InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|Unauthorized
     */
    public function handle(AddAttributeSet $command): string
    {
        $this->change->authorize(self::PERMISSION);
        $name = LocalizedName::of($command->nameAr, $command->nameEn, AttributeSet::NAME_MAX);
        $id = $this->attributes->nextId();

        return $this->change->run(ListLocks::ATTRIBUTES, function () use ($command, $name, $id): array {
            $set = AttributeSet::add($id, $name, $this->members->read($command->attributeIds));
            $this->attributes->addSet($set);

            return [$id, [ListAudit::added('attribute_set', $id, $set->snapshot())]];
        });
    }
}
