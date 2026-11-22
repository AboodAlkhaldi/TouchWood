<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\External;

use Modules\Content\Domain\Repository\Campaigns;
use Modules\Pricing\Public\Contracts\CampaignNames;
use Modules\Pricing\Public\Dto\CampaignNameDto;

/**
 * Pricing's `CampaignNames` port, answered by Content (the build coordinator's pick after PR 5): a run's campaign by
 * name and its page in the panel, whatever the run's state - a note or a report keeps naming a run that has ended.
 * One query however many runs.
 */
final readonly class ContentCampaignNames implements CampaignNames
{
    public function __construct(
        private Campaigns $campaigns,
    ) {}

    public function named(array $runIds): array
    {
        $named = [];

        foreach ($this->campaigns->namesOf($runIds) as $runId => $run) {
            $named[] = new CampaignNameDto($runId, $run['storeId'], $run['nameAr'], $run['nameEn'] ?? $run['nameAr'], '/admin/content/campaigns/runs/'.$runId);
        }

        return $named;
    }
}
