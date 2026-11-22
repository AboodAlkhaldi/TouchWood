<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\ApplyPriceWindow;

/**
 * A sale's start or end has come (pricing.md §1.10): its sizes' prices pushed into Catalog. The moment
 * is written as `Y-m-d H:i:s.uP`, as the task was queued.
 */
final readonly class ApplyPriceWindow
{
    public function __construct(
        public string $storeId,
        public string $saleId,
        public string $moment,
    ) {}
}
