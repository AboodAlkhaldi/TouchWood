<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveBar;

/**
 * **Save Bar** (content.md §1.5, W2): the store's announcement bar - one line per language, a link, on or off, an
 * optional start and end typed in the store's zone. `version` is the one the form read (0: never saved).
 */
final readonly class SaveBar
{
    public function __construct(
        public ?string $storeId,
        public int $version,
        public ?string $textAr,
        public ?string $textEn,
        public ?string $link,
        public bool $isOn,
        public ?string $startsAt = null,
        public ?string $endsAt = null,
    ) {}
}
