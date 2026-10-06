<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One file in the media library (frontend.md 3.5, E5).
 *
 * A **private file** - a company's papers - is handed over as its name, its upload date and where
 * it is used (B2B step 3, amendment 6(b)), with what describing and deleting it need (amendment
 * 8(a)): every field below marked "null for a private file" is null for one, whatever the file
 * holds. What the page is not to show is never sent, as a private file's link never is.
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
        /** Null for a private file. */
        public ?string $mime,
        /** Null for a private file. Written for a person by the screen, in its own language, in Latin digits. */
        public ?int $bytes,
        /** Null for a private file. */
        public ?int $width,
        /** Null for a private file. */
        public ?int $height,
        public string $visibility,
        /** PENDING, READY, FAILED - or null for a file that has no variants at all, and for a private file. */
        public ?string $variantsStatus,
        /** Null for a private file. */
        public ?bool $retryable,
        public string $uploadedAt,
        public ?string $altAr,
        public ?string $altEn,
        /** A thumbnail, for an image whose variants are ready; null otherwise, and for a private file. */
        public ?string $thumbnailUrl,
        public array $usedIn,
        /** Whether a use would refuse the delete (platform.md 1.4). */
        public bool $deleteBlocked,
    ) {}
}
