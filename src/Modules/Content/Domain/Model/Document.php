<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;

/**
 * A page, a blog post or a project (content.md §1.11-§1.13): per store, Draft or Published, its words per language
 * (Arabic always, English to publish), its share image per language, and what its kind adds - a post's category and
 * cover, a post's or project's products, a project's photos.
 */
final readonly class Document
{
    /**
     * @param  array<string, DocumentText>  $texts  by language; `ar` always there
     * @param  list<string>  $products  product ids, in order
     * @param  list<string>  $photos  media ids, in order
     */
    public function __construct(
        public string $id,
        public DocumentKind $kind,
        public string $storeId,
        public Publication $status,
        public ?DateTimeImmutable $publishedAt,
        public ?string $shareAr,
        public ?string $shareEn,
        public ?string $categoryId,
        public ?string $coverMediaId,
        public array $texts,
        public array $products,
        public array $photos,
        public int $version,
        public ?string $updatedBy,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    public function text(string $locale): ?DocumentText
    {
        return $this->texts[$locale] ?? null;
    }

    /**
     * Every file it shows, by its box: the share images, the cover, the photos and each language's body images.
     *
     * @return array<string, string|null>
     */
    public function images(): array
    {
        $images = ['share_image_ar' => $this->shareAr, 'share_image_en' => $this->shareEn, 'cover' => $this->coverMediaId];

        foreach ($this->photos as $index => $photo) {
            $images['photo#'.($index + 1)] = $photo;
        }

        foreach ($this->texts as $locale => $text) {
            foreach ($text->images as $ref => $image) {
                $images["body_image_{$locale}#{$ref}"] = $image['media'];
            }
        }

        return $images;
    }

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them
     */
    public function fields(): array
    {
        $fields = [
            'status' => $this->status->value,
            'share_media_ar' => $this->shareAr,
            'share_media_en' => $this->shareEn,
            'category_id' => $this->categoryId,
            'cover_media_id' => $this->coverMediaId,
            'products' => implode(',', $this->products),
            'photos' => implode(',', $this->photos),
        ];

        foreach (['ar', 'en'] as $locale) {
            $fields = [...$fields, ...($this->texts[$locale] ?? null)?->fields($locale) ?? []];
        }

        return $fields;
    }
}
