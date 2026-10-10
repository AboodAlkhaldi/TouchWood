<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditLabel;

/**
 * A label's form, sent whole (catalog.md §1.8). Its place on a card is not in the form: the list is
 * ordered by dragging (amendment 16(d), `OrderLabels`).
 */
final readonly class EditLabel
{
    public function __construct(
        public string $labelId,
        public string $nameAr,
        public string $nameEn,
        public string $tone,
    ) {}
}
