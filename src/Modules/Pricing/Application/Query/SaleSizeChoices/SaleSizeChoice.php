<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\SaleSizeChoices;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * One size of the product the offer dialog lists: its name in the page's language ("60 cm · Oak", "One
 * Size"), its code, and its retail price in the store - none: "No price yet.", not chosen.
 */
final readonly class SaleSizeChoice
{
    public function __construct(
        public string $variantId,
        public string $name,
        public string $code,
        public ?MoneyData $retail,
    ) {}
}
