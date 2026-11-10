<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * A page's, a post's or a project's state (content.md §4.1): `DRAFT` ⇄ `PUBLISHED`, published at once, with no
 * dates (§1.1 rule 5).
 */
enum Publication: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
}
