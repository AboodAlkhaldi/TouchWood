<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddLabel;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\LabelInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Model\Label;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Shared\Application\Unauthorized;

/**
 * **Adding a label** to the one list (catalog.md §1.8), under `catalog.label.manage` with All
 * stores; each store attaches labels to its products itself.
 */
final readonly class AddLabelHandler
{
    public const string PERMISSION = CatalogPermissions::LABEL_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private LabelRepository $labels,
    ) {}

    /**
     * @return string the new label's id
     *
     * @throws InvalidCatalogAttribute|Unauthorized
     */
    public function handle(AddLabel $command): string
    {
        $this->change->authorize(self::PERMISSION);
        $label = Label::add(
            $this->labels->nextId(),
            LocalizedName::of($command->nameAr, $command->nameEn, Label::NAME_MAX),
            LabelInput::tone($command->tone),
            $command->position,
        );

        return $this->change->run(ListLocks::LABELS, function () use ($label): array {
            $this->labels->add($label);

            return [$label->id(), [ListAudit::added('label', $label->id(), $label->snapshot())]];
        });
    }
}
