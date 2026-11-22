<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A policy as its form shows it (flags-2026-11.md S3.1, S3.7): its kind (fixed once made), the stores it serves (ids),
 * its state, the number of its published text (0 before its first publish: only then may it be deleted, owner
 * 2026-11-20), the version it saves at, its words per language as a page's (English none until typed), its share image
 * per language, and whether Translate's English on it is still unread (content.md §1.3, P14).
 */
#[TypeScript]
final class PolicyData extends Data
{
    /**
     * @param  list<string>  $stores
     */
    public function __construct(
        public string $id,
        public string $kind,
        public array $stores,
        public string $status,
        public ?string $publishedAt,
        public int $textVersion,
        public int $version,
        public DocumentTextData $ar,
        public ?DocumentTextData $en,
        public ?string $shareAr,
        public ?string $shareEn,
        public bool $translationUnread,
    ) {}

    /**
     * @param  array<string, mixed>  $read  PoliciesHandler::policy()
     */
    public static function fromRead(array $read): self
    {
        $text = static fn (string $key): ?string => is_string($read[$key] ?? null) && $read[$key] !== '' ? $read[$key] : null;
        $texts = is_array($read['texts'] ?? null) ? $read['texts'] : [];

        return new self(
            (string) $text('id'),
            (string) $text('kind'),
            array_values(array_filter(is_array($read['stores'] ?? null) ? $read['stores'] : [], is_string(...))),
            (string) $text('status'),
            $text('publishedAt'),
            (int) ($read['textVersion'] ?? 0),
            (int) ($read['version'] ?? 0),
            DocumentTextData::fromRead(is_array($texts['ar'] ?? null) ? $texts['ar'] : []),
            is_array($texts['en'] ?? null) ? DocumentTextData::fromRead($texts['en']) : null,
            $text('share_media_ar'),
            $text('share_media_en'),
            ($read['translationUnread'] ?? false) === true,
        );
    }

    /**
     * Every file the form shows: the share images and each body's images.
     *
     * @return list<string>
     */
    public function mediaIds(): array
    {
        return array_values(array_filter([$this->shareAr, $this->shareEn, ...array_values($this->ar->images), ...array_values($this->en->images ?? [])]));
    }
}
