<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ScheduleBanner;

/** **Schedule Banner** (content.md §1.6, W3): a draft starts at its start (none: now) and ends at its end. */
final readonly class ScheduleBanner
{
    public function __construct(
        public string $bannerId,
        public int $version,
        /** "I read the translated English." ticked (content.md §1.3, P14). */
        public bool $read = false,
    ) {}
}
