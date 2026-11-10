<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\FillHomeSection;

/**
 * A home section's own form (content.md §1.4, W1's Edit): its words in `HomeSectionSettings`' shape, and for the
 * logos band its logos in order, each `{mediaId, nameAr, nameEn, link}`. `version` is the one the form read.
 */
final readonly class FillHomeSection
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  list<array{mediaId: string, nameAr: string|null, nameEn: string|null, link: string|null}>  $logos
     */
    public function __construct(
        public string $sectionId,
        public int $version,
        public array $settings,
        public array $logos = [],
    ) {}
}
