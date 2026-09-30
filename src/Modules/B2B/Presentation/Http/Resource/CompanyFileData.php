<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One paper under its document type (b2b.md §1.4), opened by a 30-minute link.
 */
#[TypeScript]
final class CompanyFileData extends Data
{
    public function __construct(
        public string $documentTypeId,
        public ?string $documentTypeNameAr,
        public ?string $documentTypeNameEn,
        public string $mediaId,
        /** As the person's browser named it: two sections may not hold files of one name (amendment 16(c)). */
        public string $fileName,
        /** In the home store's time (HANDOFF §4). */
        public string $uploadedAt,
        /** Under a type deactivated since: to be removed before sending (§1.3). */
        public bool $noLongerAccepted,
    ) {}
}
