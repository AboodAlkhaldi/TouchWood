<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Connections;

use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\StoreOff;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The store a Provider page's read or action names** (sync.md §1.2, §1.8, §4.1 Y3): a store id, of a
 * store that exists - and, off, one only whoever may switch stores works in (pricing.md §4.3.1 Q1;
 * `StoreOff`). The handler asks for `sync.connection.manage` itself, before this.
 */
final readonly class ConnectionStore
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws InvalidSyncInput|StoreOff
     */
    public function of(string $storeId): StoreId
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidSyncInput('store');
        }

        $found = $this->platform->store($store) ?? throw new InvalidSyncInput('store');

        if (! $found->isActive && $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) === []) {
            throw new StoreOff;
        }

        return $store;
    }
}
