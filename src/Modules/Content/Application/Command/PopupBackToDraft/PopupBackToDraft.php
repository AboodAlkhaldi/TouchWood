<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\PopupBackToDraft;

/** **Back to Draft** (content.md §4.1, W6): a scheduled pop-up, not yet live, is a draft again. */
final readonly class PopupBackToDraft
{
    public function __construct(
        public string $popupId,
    ) {}
}
