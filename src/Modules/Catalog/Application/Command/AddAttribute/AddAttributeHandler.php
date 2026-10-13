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
use Modules\Catalog\Domain\Repository\ListOrders;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Shared\Application\Unauthorized;

/**
 * **Adding an attribute** (catalog.md §1.7) to the one shared library, under
 * `catalog.attribute.manage` with All stores. **It goes last** in the attributes' order, read under
 * their lock (amendment 16(d), P29).
 */
final readonly class AddAttributeHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
        private ListOrders $orders,
    ) {}

    /**
     * @return string the new attribute's id
     *
     * @throws InvalidCatalogAttribute|Unauthorized
     */
    public function handle(AddAttribute $command): string
    {
        $this->change->authorize(self::PERMISSION);
        $id = $this->attributes->nextId();
        $name = LocalizedName::of($command->nameAr, $command->nameEn, Attribute::NAME_MAX);
        $kind = AttributeInput::kind($command->kind);

        return $this->change->run(ListLocks::ATTRIBUTES, function () use ($command, $id, $name, $kind): array {
            $attribute = Attribute::add($id, $name, $kind, $command->unitAr, $command->unitEn, $command->isColour, $this->orders->afterLast(ListOrders::ATTRIBUTES));
            $this->attributes->add($attribute);

            return [$attribute->id(), [ListAudit::added('attribute', $attribute->id(), $attribute->snapshot())]];
        });
    }
}
