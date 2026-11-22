<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;

/**
 * A page, a blog post or a project (content.md §1.11-§1.13): per store, Draft or Published, its words per language
 * (Arabic always, English to publish), its share image per language, and what its kind adds - a post's category and
 * cover, a post's or project's products, a project's photos and its YouTube videos (flags-2026-11.md S4.4).
 */
final readonly class Document
{
    /**
     * @param  array<string, DocumentText>  $texts  by language; `ar` always there
     * @param  list<string>  $products  product ids, in order
     * @param  list<string>  $photos  media ids, in order
     * @param  list<ProjectVideo>  $videos  a project's, in order
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
        public ?string $clientLogoMediaId = null,
        public ?string $clientLink = null,
        public array $videos = [],
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
        $images = ['share_image_ar' => $this->shareAr, 'share_image_en' => $this->shareEn, 'cover' => $this->coverMediaId, 'client_logo' => $this->clientLogoMediaId];

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
            'client_logo_media' => $this->clientLogoMediaId,
            'client_link' => $this->clientLink,
            // A project's videos by value (§1.1 rule 13): their ids in order, then each one's titles in that order.
            'videos' => $this->videos === [] ? null : implode(',', array_map(static fn (ProjectVideo $video): string => $video->videoId, $this->videos)),
            'video_titles_ar' => self::titles($this->videos, 'ar'),
            'video_titles_en' => self::titles($this->videos, 'en'),
        ];

        foreach (['ar', 'en'] as $locale) {
            $fields = [...$fields, ...($this->texts[$locale] ?? null)?->fields($locale) ?? []];
        }

        return $fields;
    }

    /**
     * Each video's title in one language, in the videos' order and joined by " | " (an untitled one empty); null when
     * no video has one there.
     *
     * @param  list<ProjectVideo>  $videos
     */
    private static function titles(array $videos, string $locale): ?string
    {
        $titles = array_map(static fn (ProjectVideo $video): string => ($locale === 'ar' ? $video->titleAr : $video->titleEn) ?? '', $videos);

        return implode('', $titles) === '' ? null : implode(' | ', $titles);
    }
}
