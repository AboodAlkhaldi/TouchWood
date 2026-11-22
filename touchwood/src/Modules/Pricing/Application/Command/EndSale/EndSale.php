<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\EndSale;

/**
 * A running sale ended now (pricing.md §3, §4.1).
 */
final readonly class EndSale
{
    public function __construct(
        public string $saleId,
    ) {}
}
