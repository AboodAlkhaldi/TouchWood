<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateAttribute;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Activating an attribute again** (catalog.md §1.7, §9.3 #14): offered again.
 */
final readonly class ActivateAttributeHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(ActivateAttribute $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::ATTRIBUTES, function () use ($command): array {
            $attribute = $this->attributes->byId($command->attributeId) ?? throw new ListItemNotFound($command->attributeId);
            $attribute->activate();
            $entry = ListAudit::changed('attribute', 'activated', $attribute->id(), $attribute->pullChanges(), $attribute->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->attributes->update($attribute);

            return [null, [$entry]];
        });
    }
}
