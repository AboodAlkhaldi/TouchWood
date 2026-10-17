<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewConnection;

use DateTimeInterface;
use InvalidArgumentException;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Provider\ProviderAdapters;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\ValueObject\ProviderKind;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * A store's Provider page (sync.md §1.2, §1.8), for whoever may wire it: never the key or the secret,
 * only that they are set and when; and, while wired, how many sizes would stop being orderable.
 */
final readonly class ViewConnectionHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private Connections $connections,
        private ProviderAdapters $adapters,
        private ProviderStock $stock,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput
     */
    public function handle(ViewConnection $query): ConnectionView
    {
        $this->authorizer->authorize(SyncPermissions::CONNECTION_MANAGE, PermissionScope::global());

        try {
            $store = StoreId::fromString($query->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidSyncInput('store');
        }

        if ($this->platform->store($store) === null) {
            throw new InvalidSyncInput('store');
        }

        $connection = $this->connections->find($store);

        if ($connection === null) {
            return new ConnectionView($store->value, null, null, [], $this->adapters->for(ProviderKind::Odoo)->fields(), false, null, null, null, null, 0);
        }

        return new ConnectionView(
            $store->value,
            $connection->provider->value,
            $connection->address,
            $connection->settings,
            $this->adapters->for($connection->provider)->fields(),
            true,
            $connection->updatedAt?->format(DateTimeInterface::ATOM),
            $connection->state->value,
            $connection->lastReadAt?->format(DateTimeInterface::ATOM),
            $connection->lastError,
            $this->stock->stopsWhenUnwired($store),
        );
    }
}
