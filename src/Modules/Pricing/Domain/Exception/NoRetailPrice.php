<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A percentage sale for a size with no retail price there (pricing.md §1.3, §7): a percentage needs a
 * price to be taken off.
 */
final class NoRetailPrice extends PricingError
{
    /**
     * @param  list<string>  $variants  the sizes it is refused for, named for the note
     */
    public function __construct(public readonly array $variants = [])
    {
        parent::__construct('This size has no retail price in the store.');
    }

    public function type(): string
    {
        return 'pricing.no_retail_price';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['variants' => implode(',', $this->variants)];
    }
}
