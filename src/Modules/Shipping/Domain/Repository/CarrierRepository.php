<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Repository;

use Modules\Shipping\Domain\Model\Carrier;

interface CarrierRepository
{
    public function nextId(): string;

    /**
     * The store's carriers' lock, held to the end of the transaction: every change to a store's
     * carriers takes it first, and so does Platform's question before a ship-to country is taken off
     * (§1.3), so a carrier is never created in a country being removed.
     */
    public function lockStore(string $storeId): void;

    /** The store's carrier, or null when there is none - or it is another store's. */
    public function byId(string $storeId, string $carrierId): ?Carrier;

    /**
     * The store's carriers, by country, then by their place in the country's list.
     *
     * @return list<Carrier>
     */
    public function inStore(string $storeId): array;

    public function add(Carrier $carrier): void;

    public function update(Carrier $carrier): void;

    public function delete(string $carrierId): void;

    /**
     * The other carrier of the store with this name in that language, ignoring case and outer
     * spaces (step 3 Q4), or null.
     *
     * @param  'ar'|'en'  $language
     */
    public function named(string $storeId, string $language, string $name, ?string $exceptId): ?Carrier;

    /**
     * The carriers of the store in one country, by their place in the list.
     *
     * @return array<string, int> carrier id => position
     */
    public function positions(string $storeId, string $countryCode): array;

    /**
     * @param  array<string, int>  $positions  carrier id => position
     */
    public function writePositions(array $positions): void;

    public function countIn(string $storeId, string $countryCode): int;

    /**
     * The carriers showing this logo, any store's.
     *
     * @return list<Carrier>
     */
    public function withLogo(string $mediaId): array;
}
