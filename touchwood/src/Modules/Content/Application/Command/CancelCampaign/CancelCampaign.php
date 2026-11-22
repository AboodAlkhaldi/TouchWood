<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\CancelCampaign;

/** Cancel Campaign (content.md §1.7 #12): its scheduled runs, all of them or one store's (`storeId`). */
final readonly class CancelCampaign
{
    public function __construct(
        public string $campaignId,
        public ?string $storeId = null,
    ) {}
}
