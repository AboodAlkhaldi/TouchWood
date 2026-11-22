<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\PublishProject;

/** **Publish Project** (content.md §1.13, §4.1, W9), at the version the form read. */
final readonly class PublishProject
{
    public function __construct(
        public string $id,
        public int $version,
        /** "I read the translated English." ticked (§1.3, P14). */
        public bool $read = false,
    ) {}
}
