<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveSmartBar;

/**
 * W2's smart bar dialog (content.md §1.9): a new one (`smartBarId` null, in `storeId`) or one that exists - its
 * message per language, where it shows (`CATEGORY` with its category; `CAMPAIGN` with a run, plan PR 6), a link and
 * a category bar's optional dates, typed in the store's zone.
 */
final readonly class SaveSmartBar
{
    public function __construct(
        public ?string $smartBarId,
        public ?string $storeId,
        public string $scope,
        public ?string $categoryId,
        public ?string $runId,
        public ?string $messageAr,
        public ?string $messageEn,
        public ?string $link,
        public ?string $startsAt = null,
        public ?string $endsAt = null,
    ) {}
}
