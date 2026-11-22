<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One paper of a sent application, as staff read it (b2b.md §4.6). Its file's id and name go only to
 * someone who may open company papers: a reader without the private-files permission is never told
 * which file exists (amendment 8(c)), and the name is the company's own.
 */
#[TypeScript]
final class StaffFileData extends Data
{
    public function __construct(
        public string $documentTypeId,
        public ?string $documentTypeNameAr,
        public ?string $documentTypeNameEn,
        /** Null unless the reader may open company papers. */
        public ?string $mediaId,
        /** Empty unless the reader may open company papers. */
        public string $fileName,
        /** In the home store's time (HANDOFF §4). */
        public string $uploadedAt,
    ) {}
}
