<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateAttribute;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Deactivating an attribute** (catalog.md §1.7, §9.3 #14):
 * no longer offered for new use, and back by activating it; what already carries it keeps it.
 * Deleting is for what nothing uses.
 */
final readonly class DeactivateAttributeHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(DeactivateAttribute $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command): array {
            $attribute = $this->attributes->byId($command->attributeId) ?? throw new ListItemNotFound($command->attributeId);
            $attribute->deactivate();
            $entry = ListAudit::changed('attribute', 'deactivated', $attribute->id(), $attribute->pullChanges(), $attribute->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->attributes->update($attribute);

            return [null, [$entry]];
        });
    }
}
