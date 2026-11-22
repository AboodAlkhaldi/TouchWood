<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\DeleteCarrier;

/**
 * A carrier gone for good, only while it is switched off (shipping.md §1.3, owner 2026-10-28: "switch
 * then remove"), its price groups with it. Old orders never need it: Sales keeps its own copy.
 */
final readonly class DeleteCarrier
{
    public function __construct(
        public string $storeId,
        public string $carrierId,
    ) {}
}
