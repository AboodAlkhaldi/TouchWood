<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/**
 * A cart's prices in one store (pricing.md §2.2): every priced line, the variants with no price there
 * (not on sale, §1.5), the subtotals over the priced lines, and the store's VAT rate at the time - so
 * `PricingApi::totals` needs nothing else. A quote built on these prices must not outlive
 * `validUntil`, the earliest moment one of them stops applying.
 */
final readonly class CartPricesDto
{
    /**
     * @param  list<LinePriceDto>  $lines
     * @param  list<string>  $unpriced  variant ids
     */
    public function __construct(
        public string $storeId,
        public string $currencyCode,
        public int $taxRateBasisPoints,
        public array $lines,
        public array $unpriced,
        public Money $grossSubtotal,
        public Money $netSubtotal,
        public DateTimeImmutable $pricedAt,
        public ?DateTimeImmutable $validUntil,
    ) {}
}
