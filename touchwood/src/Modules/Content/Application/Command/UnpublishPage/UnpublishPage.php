<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UnpublishPage;

/** **Unpublish Page** (content.md §1.11, §4.1, W7), at the version the form read. */
final readonly class UnpublishPage
{
    public function __construct(
        public string $id,
        public int $version,
    ) {}
}
