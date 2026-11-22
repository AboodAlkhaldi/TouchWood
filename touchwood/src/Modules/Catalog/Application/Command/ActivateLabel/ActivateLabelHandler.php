<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateLabel;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Activating a label again** (catalog.md §1.8, §9.3 #14): offered again.
 */
final readonly class ActivateLabelHandler
{
    public const string PERMISSION = CatalogPermissions::LABEL_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private LabelRepository $labels,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(ActivateLabel $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::LABELS, function () use ($command): array {
            $label = $this->labels->byId($command->labelId) ?? throw new ListItemNotFound($command->labelId);
            $label->activate();
            $entry = ListAudit::changed('label', 'activated', $label->id(), $label->pullChanges(), $label->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->labels->update($label);

            return [null, [$entry]];
        });
    }
}
