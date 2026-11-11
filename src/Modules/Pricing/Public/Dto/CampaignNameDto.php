<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

/**
 * A campaign run as a note or a report names it (`CampaignNames`): the run's id and store, the campaign's name in both
 * languages, and the run's page in the panel (a path, for whoever may open it).
 */
final readonly class CampaignNameDto
{
    public function __construct(
        public string $runId,
        public string $storeId,
        public string $nameAr,
        public string $nameEn,
        public string $path,
    ) {}
}
