<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;

/**
 * A store's SEO defaults (content.md §1.14, §5 `content.seo_defaults`, W10): the site's name, the title pattern and
 * a description in each language, a share image, and the robots lines typed for the store, one shop path a line.
 */
final readonly class SeoDefaults
{
    /**
     * @param  list<string>  $robotsLines  shop paths without the store and language: `/brands/old`
     */
    public function __construct(
        public string $storeId,
        public string $siteNameAr,
        public string $siteNameEn,
        public string $titlePatternAr,
        public string $titlePatternEn,
        public string $descriptionAr,
        public string $descriptionEn,
        public ?string $shareMediaId,
        public array $robotsLines,
        public ?string $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Its short fields, for the audit (§1.1 rule 13).
     *
     * @return array<string, string|null>
     */
    public function fields(): array
    {
        return [
            'site_name_ar' => $this->siteNameAr,
            'site_name_en' => $this->siteNameEn,
            'title_pattern_ar' => $this->titlePatternAr,
            'title_pattern_en' => $this->titlePatternEn,
            'description_ar' => $this->descriptionAr,
            'description_en' => $this->descriptionEn,
            'share_media_id' => $this->shareMediaId,
            'robots_extra' => implode("\n", $this->robotsLines),
        ];
    }
}
