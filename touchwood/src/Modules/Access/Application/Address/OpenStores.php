<?php

declare(strict_types=1);

namespace Modules\Access\Application\Address;

use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * Whether a store is **on** (platform.md §1.6; access.md §1.9, amendment 53). The addresses saved in
 * an off store are hidden, not deleted — from the address book, checkout and every other module's
 * read — and its country is not offered for a new address, until it is on again. One question, asked
 * the same way by every read and every change of an address.
 */
final readonly class OpenStores
{
    public function __construct(
        private PlatformApi $platform,
    ) {}

    public function isOn(string $storeId): bool
    {
        try {
            return $this->platform->store(StoreId::fromString($storeId))?->isActive === true;
        } catch (InvalidArgumentException) {
            // Not a store id at all: no store, so nothing of it is shown.
            return false;
        }
    }
}
