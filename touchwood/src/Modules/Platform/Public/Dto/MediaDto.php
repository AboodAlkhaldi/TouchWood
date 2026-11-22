<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;

final readonly class MediaDto
{
    /**
     * @param  MediaVariantsStatus|null  $variantsStatus  null when the file has no variants (PDFs, private files)
     */
    public function __construct(
        public string $id,
        public MediaVisibility $visibility,
        public string $mime,
        public int $bytes,
        public ?int $width,
        public ?int $height,
        public string $originalFilename,
        public ?string $altAr,
        public ?string $altEn,
        public ?MediaVariantsStatus $variantsStatus,
    ) {}
}
