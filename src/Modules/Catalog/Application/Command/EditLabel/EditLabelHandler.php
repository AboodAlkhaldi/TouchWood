<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditLabel;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\LabelInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Model\Label;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Shared\Application\Unauthorized;

/**
 * **Editing a label** (catalog.md §1.8): its words, its look and its place in the list — and so on
 * every card that carries it, in every store.
 */
final readonly class EditLabelHandler
{
    public const string PERMISSION = CatalogPermissions::LABEL_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private LabelRepository $labels,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(EditLabel $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $name = LocalizedName::of($command->nameAr, $command->nameEn, Label::NAME_MAX);
        $tone = LabelInput::tone($command->tone);

        $this->change->run(ListLocks::LABELS, function () use ($command, $name, $tone): array {
            $label = $this->labels->byId($command->labelId) ?? throw new ListItemNotFound($command->labelId);
            $label->edit($name, $tone, $command->position);
            $entry = ListAudit::changed('label', 'edited', $label->id(), $label->pullChanges(), $label->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->labels->update($label);

            return [null, [$entry]];
        });
    }
}
