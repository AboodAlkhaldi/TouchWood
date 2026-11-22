<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Contracts;

use Modules\Pricing\Public\Dto\CampaignNameDto;

/**
 * **A campaign run's name and page, for Pricing's notes and Sync's report** (pricing.md §9.6 #5; the build
 * coordinator's pick after PR 5, option (a)): Pricing knows a run only by Content's run id, so this port - declared
 * here, **bound by Content**, its default answering none - names it without an arrow from Pricing or Sync to Content.
 * Sync reads names through Pricing, as it reads `salesNamed`. Module to module: no permission is checked here.
 */
interface CampaignNames
{
    /**
     * These runs named, each one Content still knows; an unknown id left out. A fixed few queries however many.
     *
     * @param  list<string>  $runIds
     * @return list<CampaignNameDto>
     */
    public function named(array $runIds): array;
}
