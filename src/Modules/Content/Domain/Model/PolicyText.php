<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use Modules\Content\Domain\ValueObject\RichText;

/**
 * A policy's words in one language (flags-2026-11.md S3.1): title, address (unique per store and language, owner
 * 2026-11-20), body in Content's formatted text
 * (content.md §1.2), SEO title and description as a page's. `images` are the body's image rows, ref => [media id,
 * description].
 */
final readonly class PolicyText
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
        public array $images,
    ) {}

    /** Whether another language's words are these: every box, the body's images by ref included. */
    public function sameAs(self $other): bool
    {
        $images = $this->images;
        $otherImages = $other->images;
        ksort($images);
        ksort($otherImages);

        return $this->title === $other->title
            && $this->slug === $other->slug
            && $this->body->equals($other->body)
            && $this->seoTitle === $other->seoTitle
            && $this->seoDescription === $other->seoDescription
            && $images === $otherImages;
    }

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
        ];
    }
}
