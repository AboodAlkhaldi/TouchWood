<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Changing the prices of a store that is off without the store switch (pricing.md §1.1 rule 8, §3,
 * §7): an off store's prices are set by Super Admins only, or by its file (owner, 2026-10-07).
 */
final class StoreOff extends PricingError
{
    public function __construct()
    {
        parent::__construct('This store is off: only whoever may switch stores changes its prices.');
    }

    public function type(): string
    {
        return 'pricing.store_off';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
