<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Service\PercentOff;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Shared\Domain\ValueObject\Money;

/**
 * One thing a campaign run prices (pricing.md §9.6 #1): a product, size, category or brand by id, a
 * percentage off in basis points - or, a size only, a fixed price (`amount`).
 */
final readonly class CampaignTarget
{
    public function __construct(
        public CampaignTargetKind $kind,
        public string $id,
        public ?int $percentBasisPoints,
        public ?Money $amount,
    ) {}

    /**
     * Its price on a size of this retail price: the percentage off, half up to the coin, or the fixed
     * price itself. Null where a percentage would leave 0 (§1.1 rule 5).
     */
    public function priceOn(Money $retail): ?Money
    {
        if ($this->amount !== null) {
            return $this->amount;
        }

        try {
            return PercentOff::price($retail, $this->percentBasisPoints ?? 0);
        } catch (PriceNotPositive) {
            return null;
        }
    }
}
