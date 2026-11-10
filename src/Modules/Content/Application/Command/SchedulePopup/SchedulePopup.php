<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SchedulePopup;

/** Scheduling a draft pop-up (content.md §1.10, W6): it shows from its start (none: now) to its end. */
final readonly class SchedulePopup
{
    public function __construct(
        public string $popupId,
        public int $version,
    ) {}
}
