<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Shared\Domain\ValueObject\Money;

/**
 * One thing a campaign prices (pricing.md §9.6 #1, #8): a product, size, category or brand by id with a
 * percentage off in basis points (`percentBasisPoints`, 1 to 9999) - or, for a size only, a fixed price
 * in the store's currency (`amount`). Exactly one of the two.
 */
final readonly class CampaignTargetDto
{
    public function __construct(
        public CampaignTargetKind $kind,
        public string $id,
        public ?int $percentBasisPoints = null,
        public ?Money $amount = null,
    ) {}
}
