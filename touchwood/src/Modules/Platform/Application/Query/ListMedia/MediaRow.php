<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListMedia;

use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;

/**
 * One file in the media library (frontend.md 3.5, E5).
 */
final readonly class MediaRow
{
    /**
     * @param  list<string>  $usedIn  where the file is used, as each module describes it
     */
    public function __construct(
        public string $id,
        public string $originalFilename,
        public string $mime,
        public int $bytes,
        public ?int $width,
        public ?int $height,
        public MediaVisibility $visibility,
        public ?MediaVariantsStatus $variantsStatus,
        /** Whether a failed or long-pending image may be tried again. */
        public bool $retryable,
        public string $uploadedAt,
        public ?string $altAr,
        public ?string $altEn,
        public array $usedIn,
        /** Whether any of those uses would refuse a delete (platform.md 1.4). */
        public bool $deleteBlocked,
    ) {}
}
