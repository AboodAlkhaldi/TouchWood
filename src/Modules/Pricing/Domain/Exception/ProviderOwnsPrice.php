<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Setting or removing a retail price in a store wired to a provider (pricing.md §1.1 rule 9, §7): the
 * provider's price is read-only in the panel (handoff §12.2).
 */
final class ProviderOwnsPrice extends PricingError
{
    public function __construct()
    {
        parent::__construct("This store's retail prices come from its provider.");
    }

    public function type(): string
    {
        return 'pricing.provider_owns_price';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
