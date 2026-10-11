<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\MoneyException;

/**
 * An amount as staff type it, in the store's currency (pricing.md §1.1 rules 4 and 5): a plain decimal
 * with no more decimals than the currency has - refused, never rounded (owner, 2026-10-07) - and above
 * 0. Read once, at the edge, before the change's transaction.
 */
final class AmountInput
{
    /**
     * @throws InvalidPricingInput|PriceTooPrecise|PriceNotPositive
     */
    public static function of(string $amount, StoreDto $store): Money
    {
        $amount = trim($amount);

        if (preg_match('/\A\d+(?:\.(\d+))?\z/', $amount, $parts) !== 1) {
            throw new InvalidPricingInput('amount');
        }

        if (strlen($parts[1] ?? '') > $store->currencyExponent) {
            throw new PriceTooPrecise($store->currencyExponent);
        }

        try {
            $money = Money::fromDecimal($amount, $store->currencyCode, $store->currencyExponent);
        } catch (MoneyException) {
            // Too large to keep in minor units.
            throw new InvalidPricingInput('amount');
        }

        if (! $money->isPositive()) {
            throw new PriceNotPositive;
        }

        return $money;
    }
}
