<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditAttribute;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\AttributeInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\AttributeKindLocked;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Model\Attribute;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Public\Enums\AttributeKind;
use Shared\Application\Unauthorized;

/**
 * **Editing an attribute** (catalog.md §1.7). Its job, and being a colour, change only while it has
 * no values (amendment 1(i)) and no variant carries a detail of it (3(k)) — asked under the attributes' lock, so a value added at the same moment
 * is seen. An attribute a set holds stays variant-making, values or none: the set is made of
 * variant-making attributes only.
 */
final readonly class EditAttributeHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
        private VariantRepository $variants,
    ) {}

    /**
     * @throws AttributeKindLocked|InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(EditAttribute $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $name = LocalizedName::of($command->nameAr, $command->nameEn, Attribute::NAME_MAX);
        $kind = AttributeInput::kind($command->kind);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command, $name, $kind): array {
            $attribute = $this->attributes->byId($command->attributeId) ?? throw new ListItemNotFound($command->attributeId);

            if ($kind !== AttributeKind::Variant && $this->attributes->inSets($attribute->id())) {
                throw new InvalidCatalogAttribute('kind', 'variant-making while an attribute set holds it');
            }

            // Its job stays once it has values (amendment 1(i)), or variants carry details of it (3(k)).
            $used = $this->attributes->hasValues($attribute->id()) || $this->variants->anyWithAttribute($attribute->id());
            $attribute->edit($name, $kind, $command->unitAr, $command->unitEn, $command->isColour, $command->position, $used);
            $entry = ListAudit::changed('attribute', 'edited', $attribute->id(), $attribute->pullChanges(), $attribute->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->attributes->update($attribute);

            return [null, [$entry]];
        });
    }
}
