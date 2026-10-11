<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteAttribute;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemInUse;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Shared\Application\Unauthorized;

/**
 * **Deleting an attribute** (catalog.md §1.7, §9.3 #14): only one nothing uses — no product makes
 * its variants of it (amendment 16(b)) — and its values go first, each deleted and audited. **Never one a variant carries**, as a value
 * or a detail, nor one a product filters by (`ListItemInUse`), asked after the attribute's row is locked.
 */
final readonly class DeleteAttributeHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
        private VariantRepository $variants,
        private ProductRepository $products,
    ) {}

    /**
     * @throws ListItemInUse|ListItemNotFound|Unauthorized
     */
    public function handle(DeleteAttribute $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command): array {
            $attribute = $this->attributes->byId($command->attributeId) ?? throw new ListItemNotFound($command->attributeId);

            if ($this->attributes->makesVariants($attribute->id()) || $this->variants->anyWithAttribute($attribute->id()) || $this->products->anyWithFilterAttribute($attribute->id())) {
                throw new ListItemInUse;
            }

            $entries = [];

            // One by one: the database refuses an attribute that still has a value (RESTRICT).
            foreach ($this->attributes->valuesOf($attribute->id()) as $value) {
                $entries[] = ListAudit::deleted('attribute_value', $value->id(), ['attribute_id' => $attribute->id(), ...$value->snapshot()]);
                $this->attributes->deleteValue($value->id());
            }

            $entries[] = ListAudit::deleted('attribute', $attribute->id(), $attribute->snapshot());
            $this->attributes->delete($attribute->id());

            return [null, $entries];
        });
    }
}
