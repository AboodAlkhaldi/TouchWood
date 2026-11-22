<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Repository;

use Modules\Shipping\Domain\Model\PriceGroup;

/**
 * A carrier's price groups (shipping.md §1.4). Changed only under the store's carriers' lock
 * (CarrierRepository::lockStore), so the handler's view of a carrier's groups stays true to the save.
 */
interface PriceGroupRepository
{
    public function nextId(): string;

    /**
     * The carrier's groups, by amount, each with its places.
     *
     * @return list<PriceGroup>
     */
    public function ofCarrier(string $carrierId): array;

    /**
     * Writes the carrier's groups as they now are: the groups given are added or changed, with
     * exactly their places; the carrier's other groups are deleted. Places leave before any arrives,
     * so a place moving between two groups never meets itself (rule 3's key).
     *
     * @param  list<PriceGroup>  $groups
     */
    public function saveCarrier(string $carrierId, array $groups): void;
}
