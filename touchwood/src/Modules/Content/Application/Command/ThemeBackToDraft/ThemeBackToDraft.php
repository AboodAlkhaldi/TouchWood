<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ThemeBackToDraft;

/** A scheduled theme that has started nowhere, back to a draft (content.md §4.1, W5b). */
final readonly class ThemeBackToDraft
{
    public function __construct(
        public string $themeId,
        public int $version,
    ) {}
}
