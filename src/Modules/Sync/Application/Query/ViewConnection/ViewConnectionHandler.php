<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewConnection;

use DateTimeInterface;
use InvalidArgumentException;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sync\Application\Connections\NoticeAddresses;
use Modules\Sync\Application\Notifications\NotificationReceiver;
use Modules\Sync\Application\Query\SyncStatus\SyncStatusView;
use Modules\Sync\Application\Report\FailureView;
use Modules\Sync\Application\Report\Moment;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\Report\SyncWords;
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
 * A store's Provider page (sync.md §1.2, §1.8, §4.1 Y3), for whoever may wire it: never the key or the
 * secret, only that they are set and when; the state, the last read and the last full read as the status
 * line says them (Q29); the notice address and what the provider sends to it (S7); whether the provider's
 * connection is built yet (Q38); and, while wired, how many sizes would stop being orderable.
 */
final readonly class ViewConnectionHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private Connections $connections,
        private ProviderAdapters $adapters,
        private ProviderStock $stock,
        private SyncLog $log,
        private SyncWords $words,
        private NoticeAddresses $notices,
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
        $providers = array_map(static fn (ProviderKind $kind): string => $kind->value, ProviderKind::cases());
        $notice = $this->notices->for($store);

        if ($connection === null) {
            return new ConnectionView($store->value, $providers, null, null, $this->adapters->for(ProviderKind::Odoo)->ready(), null, [], $this->adapters->for(ProviderKind::Odoo)->fields(), false, null, ConnectionView::NOT_WIRED, null, null, null, $notice, NotificationReceiver::MOST_CODES, 0);
        }

        $status = $this->log->status($store);
        $adapter = $this->adapters->for($connection->provider);
        $failing = $status['state'] === 'FAILING';

        return new ConnectionView(
            $store->value,
            $providers,
            $connection->provider->value,
            $this->words->line("admin_report.providers.{$connection->provider->value}"),
            $adapter->ready(),
            $connection->address,
            $connection->settings,
            $adapter->fields(),
            true,
            $connection->updatedAt?->format(DateTimeInterface::ATOM),
            $failing ? SyncStatusView::FAILING : SyncStatusView::WORKING,
            $failing ? FailureView::of($status['lastError'], $this->words) : null,
            Moment::iso($status['lastReadAt']),
            Moment::iso($status['lastFullReadAt']),
            $notice,
            NotificationReceiver::MOST_CODES,
            $this->stock->stopsWhenUnwired($store),
        );
    }
}
