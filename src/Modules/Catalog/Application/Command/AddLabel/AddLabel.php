<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddLabel;

/**
 * A new label — «الشارات» on the screens (catalog.md §1.8): one or two words in each language, and
 * its look, one of the Badge's ten (`gray` … `red-subtle`).
 */
final readonly class AddLabel
{
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public string $tone,
        public int $position = 0,
    ) {}
}
