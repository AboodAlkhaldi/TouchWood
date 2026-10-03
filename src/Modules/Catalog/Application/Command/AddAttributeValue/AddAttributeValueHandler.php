<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddAttributeValue;

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
 * **Adding a value to an attribute** (catalog.md §1.7): never one named as another value of the same
 * attribute in either language, ignoring letter case (`NameTaken`) — asked under the attributes'
 * lock, with the database's unique index behind it.
 */
final readonly class AddAttributeValueHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @return string the new value's id
     *
     * @throws InvalidCatalogAttribute|ListItemNotFound|NameTaken|Unauthorized
     */
    public function handle(AddAttributeValue $command): string
    {
        $this->change->authorize(self::PERMISSION);
        $name = LocalizedName::of($command->nameAr, $command->nameEn, AttributeValue::NAME_MAX);
        $id = $this->attributes->nextId();

        return $this->change->run(ListLocks::ATTRIBUTES, function () use ($command, $name, $id): array {
            $attribute = $this->attributes->byId($command->attributeId) ?? throw new ListItemNotFound($command->attributeId);
            $value = AttributeValue::add($id, $attribute, $name, $command->swatch, $command->position);

            if ($this->attributes->valueNameTaken($attribute->id(), $name)) {
                throw new NameTaken('value');
            }

            $this->attributes->addValue($value);

            return [$id, [ListAudit::added('attribute_value', $id, ['attribute_id' => $attribute->id(), ...$value->snapshot()])]];
        });
    }
}
