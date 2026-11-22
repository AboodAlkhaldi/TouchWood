<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\RemoveSale;

/**
 * A scheduled sale removed (pricing.md §3, §4.1).
 */
final readonly class RemoveSale
{
    public function __construct(
        public string $saleId,
    ) {}
}
