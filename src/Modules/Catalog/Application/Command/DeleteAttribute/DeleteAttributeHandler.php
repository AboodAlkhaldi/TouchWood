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
use Shared\Application\Unauthorized;

/**
 * **Deleting an attribute** (catalog.md §1.7, §9.3 #14): only one nothing uses — no attribute set
 * holds it — and its values go with it, each audited. "Carried by a product or a variant" joins the
 * refusal with the products (step 3); until then no product carries one.
 */
final readonly class DeleteAttributeHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @throws ListItemInUse|ListItemNotFound|Unauthorized
     */
    public function handle(DeleteAttribute $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command): array {
            $attribute = $this->attributes->byId($command->attributeId) ?? throw new ListItemNotFound($command->attributeId);

            if ($this->attributes->inSets($attribute->id())) {
                throw new ListItemInUse;
            }

            $entries = [];

            foreach ($this->attributes->valuesOf($attribute->id()) as $value) {
                $entries[] = ListAudit::deleted('attribute_value', $value->id(), ['attribute_id' => $attribute->id(), ...$value->snapshot()]);
            }

            $entries[] = ListAudit::deleted('attribute', $attribute->id(), $attribute->snapshot());
            $this->attributes->delete($attribute->id());

            return [null, $entries];
        });
    }
}
