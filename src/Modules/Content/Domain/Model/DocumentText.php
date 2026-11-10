<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use Modules\Content\Domain\ValueObject\RichText;

/**
 * A page's, a post's or a project's words in one language (content.md §1.11-§1.14): title, address, body, SEO title
 * and description; a post's summary and cover description; a project's city. `images` are the body's image rows,
 * ref => [media id, description].
 */
final readonly class DocumentText
{
    /**
     * @param  array<int, array{media: string, alt: string}>  $images
     */
    public function __construct(
        public string $title,
        public string $slug,
        public RichText $body,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public ?string $summary,
        public ?string $coverAlt,
        public ?string $city,
        public array $images,
    ) {}

    /**
     * @return array<string, bool|int|string|null> its short fields in one language, as the audit records them
     */
    public function fields(string $locale): array
    {
        return [
            "title_{$locale}" => $this->title,
            "slug_{$locale}" => $this->slug,
            "seo_title_{$locale}" => $this->seoTitle,
            "seo_description_{$locale}" => $this->seoDescription,
            "summary_{$locale}" => $this->summary,
            "cover_alt_{$locale}" => $this->coverAlt,
            "city_{$locale}" => $this->city,
        ];
    }
}
