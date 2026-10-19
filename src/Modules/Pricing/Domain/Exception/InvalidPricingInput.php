<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A value that is not one (pricing.md §7): a store or a size that does not exist - an unknown store is
 * invalid input, "since such a store actually doesn't exist" (owner, 2026-10-10) - or an amount that is
 * not a number. Named by its field, as Catalog's invalid attribute is - and, when it concerns sizes, by
 * them (`variants`), so a screen saving many marks their rows (pricing.md §4.3.1, owner Q2).
 */
final class InvalidPricingInput extends PricingError
{
    /**
     * @param  list<string>  $variants  the sizes it concerns, as the caller named them
     */
    public function __construct(public readonly string $attribute, public readonly array $variants = [])
    {
        parent::__construct("Invalid {$attribute}.");
    }

    public function type(): string
    {
        return 'pricing.invalid_pricing_input';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return $this->variants === [] ? ['attribute' => $this->attribute] : ['attribute' => $this->attribute, 'variants' => implode(',', $this->variants)];
    }
}
