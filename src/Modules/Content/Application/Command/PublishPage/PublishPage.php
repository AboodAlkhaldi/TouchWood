<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\PublishPage;

/** **Publish Page** (content.md §1.11, §4.1, W7), at the version the form read. */
final readonly class PublishPage
{
    public function __construct(
        public string $id,
        public int $version,
        /** "I read the translated English." ticked (§1.3, P14). */
        public bool $read = false,
    ) {}
}
