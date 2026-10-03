<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteAttributeValue;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Deleting a value** (catalog.md §1.7, §9.3 #14). "Carried by a variant or a product" joins the
 * refusal with the products (step 3); until then nothing carries one.
 */
final readonly class DeleteAttributeValueHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(DeleteAttributeValue $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command): array {
            $value = $this->attributes->valueById($command->valueId) ?? throw new ListItemNotFound($command->valueId);
            $was = ['attribute_id' => $value->attributeId(), ...$value->snapshot()];
            $this->attributes->deleteValue($value->id());

            return [null, [ListAudit::deleted('attribute_value', $value->id(), $was)]];
        });
    }
}
