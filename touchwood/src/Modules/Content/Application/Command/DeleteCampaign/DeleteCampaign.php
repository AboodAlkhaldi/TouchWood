<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteCampaign;

/** Deleting a campaign whose every run is a draft (content.md §1.1 rule 11). `version` as the form read it. */
final readonly class DeleteCampaign
{
    public function __construct(
        public string $campaignId,
        public int $version,
    ) {}
}
