<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One file in the media library (frontend.md 3.5, E5).
 */
#[TypeScript]
final class MediaFileRow extends Data
{
    /**
     * @param  list<string>  $usedIn
     */
    public function __construct(
        public string $id,
        public string $filename,
        public string $mime,
        public int $bytes,
        /** The size a person reads: "1.4 MB". */
        public string $size,
        public ?int $width,
        public ?int $height,
        public string $visibility,
        /** PENDING, READY, FAILED - or null for a file that has no variants at all. */
        public ?string $variantsStatus,
        public bool $retryable,
        public string $uploadedAt,
        public ?string $altAr,
        public ?string $altEn,
        /** A thumbnail, for an image whose variants are ready; null otherwise. */
        public ?string $thumbnailUrl,
        public array $usedIn,
        /** Whether a use would refuse the delete (platform.md 1.4). */
        public bool $deleteBlocked,
    ) {}
}
