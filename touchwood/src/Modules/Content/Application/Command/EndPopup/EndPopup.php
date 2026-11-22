<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\EndPopup;

/** **End Now…** (content.md §4.1, W6): a live pop-up ends at this moment. */
final readonly class EndPopup
{
    public function __construct(
        public string $popupId,
    ) {}
}
