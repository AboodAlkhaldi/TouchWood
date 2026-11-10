<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeletePopup;

/** Deleting a pop-up (content.md §1.1 rule 11, W6): a draft only, for good. */
final readonly class DeletePopup
{
    public function __construct(
        public string $popupId,
    ) {}
}
