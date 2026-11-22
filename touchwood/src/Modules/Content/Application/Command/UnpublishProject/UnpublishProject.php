<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UnpublishProject;

/** **Unpublish Project** (content.md §1.13, §4.1, W9), at the version the form read. */
final readonly class UnpublishProject
{
    public function __construct(
        public string $id,
        public int $version,
    ) {}
}
