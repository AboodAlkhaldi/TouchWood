<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveSeoDefaults;

/**
 * W10's defaults for a store (content.md §1.14): the site's name, the title pattern and a description in each
 * language, a share image, and the robots lines, one shop path a line, as typed.
 */
final readonly class SaveSeoDefaults
{
    public function __construct(
        public string $storeId,
        public ?string $siteNameAr,
        public ?string $siteNameEn,
        public ?string $titlePatternAr,
        public ?string $titlePatternEn,
        public ?string $descriptionAr,
        public ?string $descriptionEn,
        public ?string $shareMediaId,
        public ?string $robotsLines,
    ) {}
}
