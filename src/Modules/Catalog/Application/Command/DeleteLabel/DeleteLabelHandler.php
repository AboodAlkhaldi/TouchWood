<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteLabel;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Deleting a label** (catalog.md §1.8, §9.3 #14). "Attached to a product in some store" joins the
 * refusal with the store's choices (step 4); until then none is attached.
 */
final readonly class DeleteLabelHandler
{
    public const string PERMISSION = CatalogPermissions::LABEL_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private LabelRepository $labels,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(DeleteLabel $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::LABELS, function () use ($command): array {
            $label = $this->labels->byId($command->labelId) ?? throw new ListItemNotFound($command->labelId);
            $was = $label->snapshot();
            $this->labels->delete($label->id());

            return [null, [ListAudit::deleted('label', $label->id(), $was)]];
        });
    }
}
