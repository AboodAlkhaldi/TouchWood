<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\EndTheme;

/** End Now on a live theme (content.md §4.1, W5b): it ends in every store at this moment. */
final readonly class EndTheme
{
    public function __construct(
        public string $themeId,
        public int $version,
    ) {}
}
