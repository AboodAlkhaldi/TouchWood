<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Model;

use DateTimeImmutable;
use Modules\Platform\Domain\Exception\InvalidMediaAttribute;
use Modules\Platform\Domain\Exception\InvalidMediaVariantsTransition;
use Modules\Platform\Domain\Exception\MediaTooLarge;
use Modules\Platform\Domain\Exception\UnsupportedMediaType;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;

/**
 * An uploaded file in object storage (Platform spec §1.4).
 *
 * The original never changes: replacing a file means uploading a new Media. Every original is
 * kept private. Public images are shown only through their variants, which carry no metadata
 * such as a photo's GPS location; private files are served as uploaded, through expiring links.
 */
final class Media
{
    /**
     * Accepted types by visibility (owner's decision). The type always comes from the file's
     * contents, never its name or the browser's claim.
     */
    private const array ACCEPTED_TYPES = [
        'PUBLIC' => ['image/jpeg', 'image/png', 'image/webp'],
        'PRIVATE' => ['application/pdf', 'image/jpeg', 'image/png'],
    ];

    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * A small file can still decode into a huge bitmap. These limits stop a "decompression bomb"
     * from exhausting memory while variants are generated.
     */
    public const int MAX_IMAGE_SIDE = 12000;

    public const int MAX_IMAGE_PIXELS = 50_000_000;

    /**
     * A PENDING image queued this long ago has lost its job: it may be queued again (owner's
     * decision, 2026-09-16). Generation normally takes seconds.
     */
    public const int STALE_PENDING_MINUTES = 15;

    private const int MAX_TEXT_LENGTH = 255;

    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly string $id,
        private readonly MediaVisibility $visibility,
        private readonly string $disk,
        private readonly string $objectKey,
        private readonly string $originalFilename,
        private readonly string $mime,
        private readonly int $bytes,
        private readonly ?int $width,
        private readonly ?int $height,
        private readonly string $checksum,
        private ?string $altAr,
        private ?string $altEn,
        private ?MediaVariantsStatus $variantsStatus,
        private ?DateTimeImmutable $variantsQueuedAt,
        private ?DateTimeImmutable $variantsGeneratedAt,
        private readonly ?string $uploadedBy,
    ) {}

    /**
     * @param  string  $disk  the disk that keeps originals
     * @param  int  $maxBytes  the current upload limit for this visibility, from settings
     * @param  int|null  $width  as displayed, after the photo's orientation is applied
     * @param  bool  $animated  an animated WebP, which cannot be resized
     */
    public static function upload(
        string $id,
        MediaVisibility $visibility,
        string $disk,
        string $originalFilename,
        string $mime,
        int $bytes,
        int $maxBytes,
        ?int $width,
        ?int $height,
        bool $animated,
        string $checksum,
        ?string $uploadedBy,
        DateTimeImmutable $now,
    ): self {
        if (! in_array($mime, self::ACCEPTED_TYPES[$visibility->value], true)) {
            throw new UnsupportedMediaType($mime, strtolower($visibility->value));
        }

        if ($bytes < 1) {
            throw new InvalidMediaAttribute('bytes', 'the file is empty');
        }

        if ($bytes > $maxBytes) {
            throw new MediaTooLarge("{$bytes} bytes, the limit is {$maxBytes}");
        }

        $isImage = str_starts_with($mime, 'image/');

        if ($isImage && ($width === null || $height === null || $width < 1 || $height < 1)) {
            throw new UnsupportedMediaType($mime, 'unreadable image');
        }

        if ($isImage && (max($width, $height) > self::MAX_IMAGE_SIDE || $width * $height > self::MAX_IMAGE_PIXELS)) {
            throw new MediaTooLarge("{$width}×{$height} pixels");
        }

        if ($isImage && $animated) {
            throw new UnsupportedMediaType($mime, 'animated image');
        }

        $filename = self::cleanFilename($originalFilename);
        $folder = $visibility === MediaVisibility::Public ? 'media' : 'private';
        $hasVariants = $isImage && $visibility === MediaVisibility::Public;

        return new self(
            $id,
            $visibility,
            $disk,
            "{$folder}/{$id}.".self::EXTENSIONS[$mime],
            $filename,
            $mime,
            $bytes,
            $isImage ? $width : null,
            $isImage ? $height : null,
            $checksum,
            null,
            null,
            $hasVariants ? MediaVariantsStatus::Pending : null,
            $hasVariants ? $now : null,
            null,
            $uploadedBy,
        );
    }

    public static function reconstitute(
        string $id,
        MediaVisibility $visibility,
        string $disk,
        string $objectKey,
        string $originalFilename,
        string $mime,
        int $bytes,
        ?int $width,
        ?int $height,
        string $checksum,
        ?string $altAr,
        ?string $altEn,
        ?MediaVariantsStatus $variantsStatus,
        ?DateTimeImmutable $variantsQueuedAt,
        ?DateTimeImmutable $variantsGeneratedAt,
        ?string $uploadedBy,
    ): self {
        return new self($id, $visibility, $disk, $objectKey, $originalFilename, $mime, $bytes, $width, $height, $checksum, $altAr, $altEn, $variantsStatus, $variantsQueuedAt, $variantsGeneratedAt, $uploadedBy);
    }

    /**
     * Empty text clears an alt text.
     */
    public function changeAltText(?string $ar, ?string $en): void
    {
        $ar = self::cleanText($ar, 'alt_ar');
        $en = self::cleanText($en, 'alt_en');

        if ($this->altAr !== $ar) {
            $this->altAr = $ar;
            $this->markChanged('alt_ar');
        }

        if ($this->altEn !== $en) {
            $this->altEn = $en;
            $this->markChanged('alt_en');
        }
    }

    /**
     * PENDING → READY. READY is final: originals never change, so neither do their variants.
     */
    public function markVariantsReady(DateTimeImmutable $at): void
    {
        $this->transition(MediaVariantsStatus::Pending, MediaVariantsStatus::Ready);
        $this->variantsGeneratedAt = $at;
    }

    /**
     * PENDING → FAILED, once generation has run out of attempts.
     */
    public function markVariantsFailed(): void
    {
        $this->transition(MediaVariantsStatus::Pending, MediaVariantsStatus::Failed);
    }

    /**
     * Queues generation again: FAILED → PENDING, or a stale PENDING stays PENDING with a new
     * queue time. A recent PENDING is refused — its job may still be running.
     */
    public function retryVariants(DateTimeImmutable $now): void
    {
        if ($this->variantsStatus === MediaVariantsStatus::Failed) {
            $this->transition(MediaVariantsStatus::Failed, MediaVariantsStatus::Pending);
        } elseif (! $this->isStalePending($now)) {
            throw new InvalidMediaVariantsTransition($this->variantsStatus->value ?? 'NONE', MediaVariantsStatus::Pending->value);
        }

        $this->variantsQueuedAt = $now;
        $this->markChanged('variants_queued_at');
    }

    public function isStalePending(DateTimeImmutable $now): bool
    {
        return $this->variantsStatus === MediaVariantsStatus::Pending
            && $this->variantsQueuedAt !== null
            && $this->variantsQueuedAt <= $now->modify('-'.self::STALE_PENDING_MINUTES.' minutes');
    }

    public function hasVariants(): bool
    {
        return $this->variantsStatus !== null;
    }

    /**
     * Variant keys are derived, never stored: "{object key without extension}/{size}.{format}".
     */
    public function variantKey(MediaSize $size, ImageFormat $format): string
    {
        $base = preg_replace('/\.[a-z0-9]+\z/', '', $this->objectKey);

        return "{$base}/{$size->slug()}.{$format->extension()}";
    }

    /**
     * @return list<string>
     */
    public function pullChanges(): array
    {
        [$changed, $this->changed] = [$this->changed, []];

        return $changed;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function visibility(): MediaVisibility
    {
        return $this->visibility;
    }

    /**
     * The disk that keeps the original.
     */
    public function disk(): string
    {
        return $this->disk;
    }

    public function objectKey(): string
    {
        return $this->objectKey;
    }

    public function originalFilename(): string
    {
        return $this->originalFilename;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function checksum(): string
    {
        return $this->checksum;
    }

    public function altAr(): ?string
    {
        return $this->altAr;
    }

    public function altEn(): ?string
    {
        return $this->altEn;
    }

    public function variantsStatus(): ?MediaVariantsStatus
    {
        return $this->variantsStatus;
    }

    public function variantsQueuedAt(): ?DateTimeImmutable
    {
        return $this->variantsQueuedAt;
    }

    public function variantsGeneratedAt(): ?DateTimeImmutable
    {
        return $this->variantsGeneratedAt;
    }

    public function uploadedBy(): ?string
    {
        return $this->uploadedBy;
    }

    private function transition(MediaVariantsStatus $from, MediaVariantsStatus $to): void
    {
        if ($this->variantsStatus !== $from) {
            throw new InvalidMediaVariantsTransition($this->variantsStatus->value ?? 'NONE', $to->value);
        }

        $this->variantsStatus = $to;
        $this->markChanged('variants_status');
    }

    private function markChanged(string $attribute): void
    {
        if (! in_array($attribute, $this->changed, true)) {
            $this->changed[] = $attribute;
        }
    }

    private static function cleanFilename(string $filename): string
    {
        if (! mb_check_encoding($filename, 'UTF-8')) {
            throw new InvalidMediaAttribute('original_filename', 'expected UTF-8 text');
        }

        // Keep only the name itself: no directories, no control characters, and no invisible
        // formatting characters such as a right-to-left override that disguises the extension.
        $name = trim((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', basename(str_replace('\\', '/', $filename))));

        if ($name === '' || mb_strlen($name) > self::MAX_TEXT_LENGTH) {
            throw new InvalidMediaAttribute('original_filename', 'expected a file name of 1 to 255 characters');
        }

        return $name;
    }

    private static function cleanText(?string $text, string $attribute): ?string
    {
        if ($text !== null && ! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidMediaAttribute($attribute, 'expected UTF-8 text');
        }

        $text = $text === null ? null : trim($text);

        if ($text !== null && mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new InvalidMediaAttribute($attribute, 'at most 255 characters');
        }

        return $text === '' ? null : $text;
    }
}
