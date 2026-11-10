<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SavePopup;

/**
 * A pop-up's page (content.md §1.10, W6): a new one (`popupId` null, in `storeId`) or one that exists. Dates are
 * typed in the store's zone; `delaySeconds` null is the default, 8.
 */
final readonly class SavePopup
{
    public function __construct(
        public ?string $popupId,
        public ?string $storeId,
        public int $version,
        public string $name,
        public ?string $titleAr,
        public ?string $titleEn,
        public ?string $textAr,
        public ?string $textEn,
        public ?string $buttonAr,
        public ?string $buttonEn,
        public ?string $link,
        public ?int $delaySeconds,
        public ?string $mediaId,
        public ?string $startsAt,
        public ?string $endsAt,
    ) {}
}
