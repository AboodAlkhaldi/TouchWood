<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeletePost;

/** **Delete Post…** (content.md §1.1 rule 11, W8): a draft only, for good. */
final readonly class DeletePost
{
    public function __construct(
        public string $id,
    ) {}
}
