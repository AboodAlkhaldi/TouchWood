<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deeper than the store's discount ceiling (pricing.md §1.12, §7; owner, 2026-10-13): a sale saved
 * deeper, or a retail price that would take a running or scheduled sale past it. The note names the
 * ceiling and what passes it, so staff change the price or the sale.
 */
final class OverCeiling extends PricingError
{
    /**
     * @param  list<string>  $sales  the sales that would pass it
     * @param  list<string>  $variants  the sizes it would pass it on
     */
    public function __construct(public readonly int $ceilingPercent, public readonly array $sales = [], public readonly array $variants = [])
    {
        parent::__construct("Deeper than the store's discount ceiling of {$ceilingPercent}%.");
    }

    public function type(): string
    {
        return 'pricing.over_ceiling';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['ceiling' => $this->ceilingPercent, 'sales' => implode(',', $this->sales), 'variants' => implode(',', $this->variants)];
    }
}
