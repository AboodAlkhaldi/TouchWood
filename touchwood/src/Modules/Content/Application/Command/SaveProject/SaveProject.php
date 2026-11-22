<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveProject;

use Modules\Content\Application\Documents\DocumentInput;

/** a project's form saved (content.md §1.13, W9): a new draft, or the one it is, at the version the form read. */
final readonly class SaveProject
{
    public function __construct(
        public DocumentInput $input,
    ) {}
}
