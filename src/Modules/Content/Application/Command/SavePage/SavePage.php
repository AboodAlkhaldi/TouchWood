<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SavePage;

use Modules\Content\Application\Documents\DocumentInput;

/** a page's form saved (content.md §1.11, W7): a new draft, or the one it is, at the version the form read. */
final readonly class SavePage
{
    public function __construct(
        public DocumentInput $input,
    ) {}
}
