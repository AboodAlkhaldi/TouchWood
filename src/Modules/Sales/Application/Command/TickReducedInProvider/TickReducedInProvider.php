<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\TickReducedInProvider;

/**
 * Staff ticked "reduced in the provider" for these sizes of an order in a wired store (sales.md §1.5).
 */
final readonly class TickReducedInProvider
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $orderId,
        public array $variantIds,
    ) {}
}
