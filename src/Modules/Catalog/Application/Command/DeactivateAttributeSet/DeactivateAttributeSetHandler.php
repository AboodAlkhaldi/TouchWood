<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateAttributeSet;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Deactivating an attribute set** (catalog.md §1.7, §9.3 #14):
 * no longer offered for new use, and back by activating it; what already carries it keeps it.
 * Deleting is for what nothing uses.
 */
final readonly class DeactivateAttributeSetHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(DeactivateAttributeSet $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command): array {
            $set = $this->attributes->setById($command->setId) ?? throw new ListItemNotFound($command->setId);
            $set->deactivate();
            $entry = ListAudit::changed('attribute_set', 'deactivated', $set->id(), $set->pullChanges(), $set->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->attributes->updateSet($set);

            return [null, [$entry]];
        });
    }
}
