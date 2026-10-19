<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A price, a wholesale band or a fixed amount of 0 or less (pricing.md §1.1 rule 4, §7): a free piece
 * is a gift, which is Promotions'.
 */
final class PriceNotPositive extends PricingError
{
    /**
     * @param  list<string>  $variants  the sizes it concerns - a price typed at 0 or below, a sale's percentage
     *                                  that would leave 0 - named for the note and the rows (owner Q2)
     */
    public function __construct(public readonly array $variants = [])
    {
        parent::__construct('A price must be above 0.');
    }

    public function type(): string
    {
        return 'pricing.price_not_positive';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return $this->variants === [] ? [] : ['variants' => implode(',', $this->variants)];
    }
}
