<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\NeedsAPrice;

/**
 * The Needs a Price list of one store (pricing.md §1.9, §3).
 */
final readonly class NeedsAPrice
{
    public function __construct(
        public string $storeId,
    ) {}
}
