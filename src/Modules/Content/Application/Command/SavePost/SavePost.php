<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SavePost;

use Modules\Content\Application\Documents\DocumentInput;

/** a blog post's form saved (content.md §1.12, W8): a new draft, or the one it is, at the version the form read. */
final readonly class SavePost
{
    public function __construct(
        public DocumentInput $input,
    ) {}
}
