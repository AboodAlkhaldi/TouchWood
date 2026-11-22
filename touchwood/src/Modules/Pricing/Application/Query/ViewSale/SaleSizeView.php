<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewSale;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * One size a sale covers: its name in the page's language ("60 cm · Oak", "One Size"), its code, whether
 * it is archived (or its product) or switched on in the store now, its retail price now and what the
 * sale makes it cost (none where it has no price, or a percentage would leave 0).
 */
final readonly class SaleSizeView
{
    public function __construct(
        public string $variantId,
        public string $name,
        public string $code,
        public bool $archived,
        public bool $switchedOn,
        public ?MoneyData $retail,
        public ?MoneyData $salePrice,
    ) {}
}
