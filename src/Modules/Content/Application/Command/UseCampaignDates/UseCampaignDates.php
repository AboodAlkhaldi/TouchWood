<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UseCampaignDates;

/**
 * Use Campaign's Dates (content.md §1.7 #7, Q6 (a)): the theme the campaign links to takes the campaign's start and end
 * as typed, read on each of the theme's stores' own clock. `version` is the theme's, as the page read it.
 */
final readonly class UseCampaignDates
{
    public function __construct(
        public string $campaignId,
        public int $version,
    ) {}
}
