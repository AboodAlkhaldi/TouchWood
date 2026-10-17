<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\UnwireStore;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\NotWired;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\Repository\ReportEntries;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Unwiring a store** (sync.md §1.8), together: the connection gone with its key and secret, so the
 * store is not wired from that second; its report closed and its waiting reads dropped; Inventory taking
 * the returned pieces in and telling Catalog every size again. Nothing else is cleared: the last prices
 * and stock stay, now staff's. Answers how many sizes stopped being orderable - the count the Super Admin
 * saw before confirming.
 */
final readonly class UnwireStoreHandler
{
    public const string PERMISSION = SyncPermissions::CONNECTION_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private Connections $connections,
        private Reads $reads,
        private ReportEntries $entries,
        private ProviderStock $stock,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput|NotWired
     */
    public function handle(UnwireStore $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidSyncInput('store');
        }

        return $this->db->transaction(function () use ($store): int {
            $connection = $this->connections->lockFor($store) ?? throw new NotWired;
            $this->connections->delete($store);
            $closed = $this->entries->closeAll($store, 'UNWIRED');
            $this->reads->dropQueued($store, 'The store was unwired.');
            $stopped = $this->stock->unwired($store);

            $this->platform->recordAudit(new AuditEntryDto(
                'sync.connection.unwired',
                'sync.connection',
                $store->value,
                $store->value,
                AuditChanges::none()
                    ->changed('provider', $connection->provider->value, null)
                    ->changed('url', $connection->address, null)
                    ->changed('stopped_orderable', null, $stopped)
                    ->changed('entries_closed', null, $closed),
            ));

            return $stopped;
        });
    }
}
