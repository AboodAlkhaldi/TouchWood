<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\External;

use Modules\Pricing\Public\Contracts\CampaignNames;

/** `CampaignNames` with no Content bound: no run is named (pricing.md §9.6 #5). Content's provider replaces it. */
final readonly class NoCampaignNames implements CampaignNames
{
    public function named(array $runIds): array
    {
        return [];
    }
}
