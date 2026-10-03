<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddAttribute;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\AttributeInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Model\Attribute;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Shared\Application\Unauthorized;

/**
 * **Adding an attribute** (catalog.md §1.7) to the one shared library, under
 * `catalog.attribute.manage` with All stores.
 */
final readonly class AddAttributeHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @return string the new attribute's id
     *
     * @throws InvalidCatalogAttribute|Unauthorized
     */
    public function handle(AddAttribute $command): string
    {
        $this->change->authorize(self::PERMISSION);
        $attribute = Attribute::add(
            $this->attributes->nextId(),
            LocalizedName::of($command->nameAr, $command->nameEn, Attribute::NAME_MAX),
            AttributeInput::kind($command->kind),
            $command->unitAr,
            $command->unitEn,
            $command->isColour,
            $command->position,
        );

        return $this->change->run(ListLocks::ATTRIBUTES, function () use ($attribute): array {
            $this->attributes->add($attribute);

            return [$attribute->id(), [ListAudit::added('attribute', $attribute->id(), $attribute->snapshot())]];
        });
    }
}
