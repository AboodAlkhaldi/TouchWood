<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteTheme;

/** Delete Theme on a draft no campaign links to (content.md §1.1 rule 11, §5). */
final readonly class DeleteTheme
{
    public function __construct(
        public string $themeId,
        public int $version,
    ) {}
}
