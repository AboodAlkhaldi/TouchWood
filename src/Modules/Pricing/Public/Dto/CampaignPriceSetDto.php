<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

use Modules\Pricing\Public\Enums\CampaignLines;

/**
 * A campaign run's prices in one store (pricing.md §9.6 #8; content.md §2.3): the lines they apply to,
 * what they price, and the products left out of its categories and brands. A product or size picked by
 * name stays as picked, left out or not.
 */
final readonly class CampaignPriceSetDto
{
    /**
     * @param  list<CampaignTargetDto>  $targets
     * @param  list<string>  $leftOut  product ids
     */
    public function __construct(
        public CampaignLines $lines,
        public array $targets,
        public array $leftOut = [],
    ) {}
}
