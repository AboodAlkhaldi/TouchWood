<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeletePage;

/** **Delete Page…** (content.md §1.1 rule 11, W7): a draft only, for good. */
final readonly class DeletePage
{
    public function __construct(
        public string $id,
    ) {}
}
