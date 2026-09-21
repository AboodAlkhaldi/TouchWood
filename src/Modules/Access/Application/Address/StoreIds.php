<?php

declare(strict_types=1);

namespace Modules\Access\Application\Address;

use InvalidArgumentException;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Shared\Domain\ValueObject\StoreId;

/**
 * The store an address belongs to, as it arrives from a caller: read once into a `StoreId`, which
 * is lower-case, so the permission check, the cache key and the stored row all use the same string
 * (review of step 5). A value that is not a store id at all is a plain "unknown store", never the
 * framework's own exception.
 */
final class StoreIds
{
    /**
     * @throws InvalidAccessAttribute
     */
    public static function of(string $storeId): StoreId
    {
        try {
            return StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidAccessAttribute('store', 'unknown');
        }
    }
}
