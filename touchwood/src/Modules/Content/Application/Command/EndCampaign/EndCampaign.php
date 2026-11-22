<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\EndCampaign;

/** End Campaign (content.md §1.7 #12): its live runs, all of them or one store's (`storeId`). */
final readonly class EndCampaign
{
    public function __construct(
        public string $campaignId,
        public ?string $storeId = null,
    ) {}
}
