<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\PublishPost;

/** **Publish Post** (content.md §1.12, §4.1, W8), at the version the form read. */
final readonly class PublishPost
{
    public function __construct(
        public string $id,
        public int $version,
    ) {}
}
