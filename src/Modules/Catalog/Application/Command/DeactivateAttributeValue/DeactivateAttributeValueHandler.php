<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateAttributeValue;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Deactivating an attribute's value** (catalog.md §1.7, §9.3 #14):
 * no longer offered for new use, and back by activating it; what already carries it keeps it.
 * Deleting is for what nothing uses.
 */
final readonly class DeactivateAttributeValueHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(DeactivateAttributeValue $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command): array {
            $value = $this->attributes->valueById($command->valueId) ?? throw new ListItemNotFound($command->valueId);
            $value->deactivate();
            $entry = ListAudit::changed('attribute_value', 'deactivated', $value->id(), $value->pullChanges(), $value->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->attributes->updateValue($value);

            return [null, [$entry]];
        });
    }
}
