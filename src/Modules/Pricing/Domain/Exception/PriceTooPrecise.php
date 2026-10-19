<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An amount typed with more decimals than the store's currency has (pricing.md §1.1 rule 5, §7):
 * refused, never rounded (owner, 2026-10-07) - naming the sizes it concerns (`variants`), so a screen saving
 * many marks their rows (§4.3.1, owner Q2).
 */
final class PriceTooPrecise extends PricingError
{
    /**
     * @param  list<string>  $variants
     */
    public function __construct(public readonly int $decimals, public readonly array $variants = [])
    {
        parent::__construct("The store's currency takes at most {$decimals} decimals.");
    }

    public function type(): string
    {
        return 'pricing.price_too_precise';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return $this->variants === [] ? ['decimals' => $this->decimals] : ['decimals' => $this->decimals, 'variants' => implode(',', $this->variants)];
    }
}
