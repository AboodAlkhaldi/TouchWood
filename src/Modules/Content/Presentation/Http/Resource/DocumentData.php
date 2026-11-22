<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A page, post or project as its form shows it (content.md §1.11-§1.13, W7-W9): its state, the version it saves at, its
 * words per language (English none until typed), its share images, a post's category and cover, a project's photos, a
 * post's or project's products, a project's YouTube videos (flags-2026-11.md S4.4), and whether Translate's English on it
 * is still unread (§1.3, P14).
 */
#[TypeScript]
final class DocumentData extends Data
{
    /**
     * @param  list<string>  $products
     * @param  list<string>  $photos
     * @param  list<array{videoId: string, titleAr: string|null, titleEn: string|null}>  $videos
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $publishedAt,
        public int $version,
        public DocumentTextData $ar,
        public ?DocumentTextData $en,
        public ?string $shareAr,
        public ?string $shareEn,
        public ?string $categoryId,
        public ?string $coverMediaId,
        public array $products,
        public array $photos,
        public bool $translationUnread,
        public ?string $clientLogoMediaId = null,
        public ?string $clientLink = null,
        public array $videos = [],
    ) {}

    /**
     * @param  array<string, mixed>  $read  DocumentsHandler::document()
     */
    public static function fromRead(array $read): self
    {
        $text = static fn (string $key): ?string => is_string($read[$key] ?? null) && $read[$key] !== '' ? $read[$key] : null;
        $texts = is_array($read['texts'] ?? null) ? $read['texts'] : [];
        $list = static fn (string $key): array => array_values(array_filter(is_array($read[$key] ?? null) ? $read[$key] : [], is_string(...)));

        return new self(
            (string) $text('id'),
            (string) $text('status'),
            $text('publishedAt'),
            (int) ($read['version'] ?? 0),
            DocumentTextData::fromRead(is_array($texts['ar'] ?? null) ? $texts['ar'] : []),
            is_array($texts['en'] ?? null) ? DocumentTextData::fromRead($texts['en']) : null,
            $text('share_media_ar'),
            $text('share_media_en'),
            $text('category_id'),
            $text('cover_media_id'),
            $list('products'),
            $list('photos'),
            ($read['translationUnread'] ?? false) === true,
            $text('client_logo_media'),
            $text('client_link'),
            self::videos($read['videos'] ?? []),
        );
    }

    /**
     * @return list<array{videoId: string, titleAr: string|null, titleEn: string|null}>
     */
    private static function videos(mixed $read): array
    {
        $videos = [];

        foreach (is_array($read) ? $read : [] as $video) {
            if (is_array($video) && is_string($video['videoId'] ?? null)) {
                $title = static fn (string $key): ?string => is_string($video[$key] ?? null) ? $video[$key] : null;
                $videos[] = ['videoId' => $video['videoId'], 'titleAr' => $title('titleAr'), 'titleEn' => $title('titleEn')];
            }
        }

        return $videos;
    }

    /**
     * Every file the form shows: share images, cover, photos, and each body's images.
     *
     * @return list<string>
     */
    public function mediaIds(): array
    {
        return array_values(array_filter([$this->shareAr, $this->shareEn, $this->coverMediaId, $this->clientLogoMediaId, ...$this->photos, ...array_values($this->ar->images), ...array_values($this->en->images ?? [])]));
    }
}
