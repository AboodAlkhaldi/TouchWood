<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * A gift level an order reaches (promotions.md §2.2): its id, amount, and the gift's variant — Sales
 * asks Inventory which is in stock, highest first, and holds that one (§1.7).
 */
final readonly class GiftOfferDto
{
    public function __construct(
        public string $levelId,
        public Money $amount,
        public string $variantId,
    ) {}
}
