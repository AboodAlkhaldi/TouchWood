<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateLabel;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Deactivating a label** (catalog.md §1.8, §9.3 #14):
 * no longer offered for new use, and back by activating it; what already carries it keeps it.
 * Deleting is for what nothing uses.
 */
final readonly class DeactivateLabelHandler
{
    public const string PERMISSION = CatalogPermissions::LABEL_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private LabelRepository $labels,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(DeactivateLabel $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::LABELS, function () use ($command): array {
            $label = $this->labels->byId($command->labelId) ?? throw new ListItemNotFound($command->labelId);
            $label->deactivate();
            $entry = ListAudit::changed('label', 'deactivated', $label->id(), $label->pullChanges(), $label->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->labels->update($label);

            return [null, [$entry]];
        });
    }
}
