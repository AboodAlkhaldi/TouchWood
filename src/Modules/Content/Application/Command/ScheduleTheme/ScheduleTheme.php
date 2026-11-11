<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ScheduleTheme;

/** Scheduling a draft theme in every store it picks (content.md §1.8, W5b's Schedule Theme). */
final readonly class ScheduleTheme
{
    public function __construct(
        public string $themeId,
        public int $version,
        /** "I read the translated English." ticked (content.md §1.3, P14). */
        public bool $read = false,
    ) {}
}
