<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use Modules\Access\Domain\Model\StoreAddressFormat;

/**
 * Each store's address shape (spec §1.9). It is read on every address saved and every address
 * shown, and changes rarely, so the reading side is cached under its own version.
 */
interface StoreAddressFormatRepository
{
    public function forStore(string $storeId): ?StoreAddressFormat;

    /** Writes or replaces the store's format. Invalidates the cache inside the same transaction. */
    public function save(StoreAddressFormat $format): void;

    public function existsFor(string $storeId): bool;
}
