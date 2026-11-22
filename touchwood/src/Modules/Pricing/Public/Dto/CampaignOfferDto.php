<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

use DateTimeImmutable;
use Modules\Pricing\Public\Enums\PriceKind;

/**
 * Another offer on a campaign's sizes in its dates (pricing.md §9.6 #8, §1.3's note): a sale (`SALE`), a
 * category discount (`CATEGORY`) or another campaign run (`CAMPAIGN`, its id the run's), its dates,
 * whether it always wins, and the sizes it shares with the campaign - the lowest price winning, but an
 * "always wins" sale or discount beating a cheaper campaign (§9.6 #3).
 */
final readonly class CampaignOfferDto
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public PriceKind $kind,
        public string $id,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public bool $alwaysWins,
        public array $variantIds,
    ) {}
}
