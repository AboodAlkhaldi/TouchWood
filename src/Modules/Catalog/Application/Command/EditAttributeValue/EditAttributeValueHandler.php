<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditAttributeValue;

use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\NameTaken;
use Modules\Catalog\Domain\Model\AttributeValue;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Shared\Application\Unauthorized;

/**
 * **Editing a value** (catalog.md §1.7): its names, never another value's, and its swatch as its
 * attribute now asks — required on a colour attribute's value, refused on any other.
 */
final readonly class EditAttributeValueHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemNotFound|NameTaken|Unauthorized
     */
    public function handle(EditAttributeValue $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $name = LocalizedName::of($command->nameAr, $command->nameEn, AttributeValue::NAME_MAX);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command, $name): array {
            $found = $this->attributes->findValue($command->valueId) ?? throw new ListItemNotFound($command->valueId);
            // The attribute's row before the value's, the order of every change that locks both — a
            // product's change too — so two never wait on each other in a circle. A value never
            // outlives its attribute, so this always finds it; the value is read again under its lock.
            $attribute = $this->attributes->byId($found->attributeId()) ?? throw new LogicException('A value without its attribute.');
            $value = $this->attributes->valueById($command->valueId) ?? throw new ListItemNotFound($command->valueId);

            $value->edit($attribute, $name, $command->swatch, $command->position);

            if ($this->attributes->valueNameTaken($attribute->id(), $name, $value->id())) {
                throw new NameTaken('value');
            }

            $entry = ListAudit::changed('attribute_value', 'edited', $value->id(), $value->pullChanges(), $value->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->attributes->updateValue($value);

            return [null, [$entry]];
        });
    }
}
