<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\DeletePriceGroup;

/**
 * A carrier's price group deleted, its places with it: the carrier no longer serves them, unless a
 * place above them is priced (shipping.md §1.4).
 */
final readonly class DeletePriceGroup
{
    public function __construct(
        public string $storeId,
        public string $carrierId,
        public string $groupId,
        public string $seenVersion,
    ) {}
}
