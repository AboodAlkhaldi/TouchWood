<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;
use Spatie\LaravelData\Data;

final class MediaDto extends Data
{
    /**
     * @param  MediaVariantsStatus|null  $variantsStatus  null when the file has no variants (PDFs, private files)
     */
    public function __construct(
        public readonly string $id,
        public readonly MediaVisibility $visibility,
        public readonly string $mime,
        public readonly int $bytes,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly string $originalFilename,
        public readonly ?string $altAr,
        public readonly ?string $altEn,
        public readonly ?MediaVariantsStatus $variantsStatus,
    ) {}
}
