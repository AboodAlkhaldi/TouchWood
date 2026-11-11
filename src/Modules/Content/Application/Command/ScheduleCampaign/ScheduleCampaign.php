<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ScheduleCampaign;

/**
 * Scheduling a campaign's draft runs (content.md §1.7 #14, #15; W4b's Schedule Campaign): every draft run, or one
 * store's (`storeId`). `version` is the campaign's, as the form read it.
 */
final readonly class ScheduleCampaign
{
    public function __construct(
        public string $campaignId,
        public int $version,
        public ?string $storeId = null,
        /** "I read the translated English." ticked (content.md §1.3, P14). */
        public bool $read = false,
    ) {}
}
