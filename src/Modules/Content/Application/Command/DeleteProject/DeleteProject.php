<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteProject;

/** **Delete Project…** (content.md §1.1 rule 11, W9): a draft only, for good. */
final readonly class DeleteProject
{
    public function __construct(
        public string $id,
    ) {}
}
