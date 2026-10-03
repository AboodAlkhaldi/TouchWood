<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateAttributeValue;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Activating an attribute's value again** (catalog.md §1.7, §9.3 #14): offered again.
 */
final readonly class ActivateAttributeValueHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(ActivateAttributeValue $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command): array {
            $value = $this->attributes->valueById($command->valueId) ?? throw new ListItemNotFound($command->valueId);
            $value->activate();
            $entry = ListAudit::changed('attribute_value', 'activated', $value->id(), $value->pullChanges(), $value->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->attributes->updateValue($value);

            return [null, [$entry]];
        });
    }
}
