<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * One of a product's YouTube videos (flags-2026-11.md S4.4, catalog.md amendment 48): the video's 11-character id -
 * the link it was pasted as is not kept - and an optional title per language, one line of at most 100 characters
 * each (CatalogText). The product's, not a size's (F22).
 */
final readonly class ProductVideo
{
    public const int TITLE_MAX = 100;

    private function __construct(
        public string $videoId,
        public ?string $titleAr,
        public ?string $titleEn,
    ) {}

    /**
     * @param  string  $videoId  as YouTube writes one, read from a link already (Platform's YouTubeLink)
     *
     * @throws InvalidCatalogAttribute
     */
    public static function of(string $videoId, ?string $titleAr, ?string $titleEn): self
    {
        return new self(
            $videoId,
            CatalogText::optionalLine('video_title_ar', $titleAr, self::TITLE_MAX),
            CatalogText::optionalLine('video_title_en', $titleEn, self::TITLE_MAX),
        );
    }

    public static function reconstitute(string $videoId, ?string $titleAr, ?string $titleEn): self
    {
        return new self($videoId, $titleAr, $titleEn);
    }

    /** As the audit log keeps it: the id, then its titles when it has any. */
    public function described(): string
    {
        return $this->titleAr === null && $this->titleEn === null ? $this->videoId : "{$this->videoId} ({$this->titleAr} / {$this->titleEn})";
    }
}
