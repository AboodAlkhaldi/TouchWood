<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Modules\Content\Application\Moments;
use Modules\Content\Domain\ValueObject\Schedule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One banner as its form shows it (content.md §1.6, §4.4 W3). `images` is `{DESKTOP|PHONE}:{any|ar|en}` => media id. */
#[TypeScript]
final class BannerData extends Data
{
    /**
     * @param  array<string, string>  $images
     */
    public function __construct(
        public string $id,
        public string $placement,
        public ?string $categoryId,
        public string $name,
        public ?string $link,
        public bool $hasWords,
        public string $altAr,
        public ?string $altEn,
        public string $state,
        public ?string $startsAt,
        public ?string $endsAt,
        public array $images,
        public int $version,
        /** Translate filled its English and nobody has ticked that they read it (content.md §1.3, P14). */
        public bool $translationUnread = false,
        /** A home banner's own words over its picture (shop-design.md §3.1). */
        public ?string $titleAr = null,
        public ?string $titleEn = null,
        public ?string $lineAr = null,
        public ?string $lineEn = null,
        public ?string $buttonAr = null,
        public ?string $buttonEn = null,
    ) {}

    /**
     * @param  array<string, mixed>  $banner  BannersHandler::banner()
     */
    public static function fromFields(array $banner): self
    {
        $text = static fn (string $key): ?string => is_string($banner[$key] ?? null) ? $banner[$key] : null;
        $moment = static fn (string $key): ?\DateTimeImmutable => $text($key) === null ? null : new \DateTimeImmutable((string) $text($key));
        $images = is_array($banner['images'] ?? null) ? array_filter($banner['images'], 'is_string') : [];

        return new self(
            (string) $text('id'),
            (string) $text('placement'),
            $text('category_id'),
            (string) $text('name'),
            $text('link'),
            (bool) ($banner['has_words'] ?? false),
            (string) $text('alt_ar'),
            $text('alt_en'),
            (new Schedule((bool) ($banner['is_scheduled'] ?? false), $moment('starts_at'), $moment('ends_at')))->state(Moments::now())->value,
            $text('starts_at'),
            $text('ends_at'),
            $images,
            (int) ($banner['version'] ?? 0),
            ($banner['translation_unread'] ?? false) === true,
            $text('title_ar'),
            $text('title_en'),
            $text('line_ar'),
            $text('line_en'),
            $text('button_ar'),
            $text('button_en'),
        );
    }
}
