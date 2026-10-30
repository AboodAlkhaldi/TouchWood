<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Repository;

use Modules\Shipping\Domain\Model\Box;

interface BoxRepository
{
    public function nextId(): string;

    /** The store's boxes' lock, held to the end of the transaction: every change of its boxes takes it. */
    public function lockStore(string $storeId): void;

    /** The store's box, or null when there is none - or it is another store's. */
    public function byId(string $storeId, string $boxId): ?Box;

    /**
     * @return list<Box> the store's boxes in their order
     */
    public function inStore(string $storeId): array;

    /** Another box of the store with this name, ignoring case and outer spaces, or null. */
    public function named(string $storeId, string $name, ?string $exceptId): ?Box;

    public function add(Box $box): void;

    public function update(Box $box): void;

    public function delete(string $boxId): void;

    /**
     * @param  array<string, int>  $positions  box id => position
     */
    public function writePositions(array $positions): void;

    /**
     * @param  list<string>  $variantIds
     * @return list<string> those packed by hand in the store
     */
    public function handPacked(string $storeId, array $variantIds): array;

    /**
     * @return list<string> every size packed by hand in the store
     */
    public function allHandPacked(string $storeId): array;

    /** A size packed by hand in the store (true), or by the suggestion (false). */
    public function setHandPacked(string $storeId, string $variantId, bool $byHand): bool;
}
