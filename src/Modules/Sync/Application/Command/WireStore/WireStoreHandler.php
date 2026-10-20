<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\WireStore;

use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sync\Application\Connections\ConnectionInput;
use Modules\Sync\Application\Connections\ConnectionStore;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\AlreadyWired;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\StoreOff;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\Provider\ProviderAdapters;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ConnectionState;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **Wiring a store** (sync.md §1.2): a Super Admin's alone (`sync.connection.manage`, reserved). The
 * connection is tested first - one small read, 10 seconds at most, the one call to the provider made in
 * a web request (§9.3 #21) - and nothing is saved when it fails. Then, together: the connection saved
 * (key and secret encrypted), the store wired that second, a full read queued, and the audit entry -
 * never the key or the secret. Then Inventory tells Catalog every size as a wired store's.
 */
final readonly class WireStoreHandler
{
    public const string PERMISSION = SyncPermissions::CONNECTION_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private ConnectionStore $stores,
        private ActorContext $actors,
        private PlatformApi $platform,
        private ConnectionInput $input,
        private ProviderAdapters $adapters,
        private Connections $connections,
        private Reads $reads,
        private ProviderStock $stock,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput|StoreOff|AlreadyWired|ConnectionFailed
     */
    public function handle(WireStore $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        // A store that exists; off, only for whoever may switch stores (Q1).
        $store = $this->stores->of($command->storeId);

        $provider = $this->input->provider($command->provider);
        $connection = new Connection(
            $store->value,
            $provider,
            $this->input->address($provider, $command->address),
            $this->input->settings($provider, $command->settings),
            $this->input->secret($command->key, 'key'),
            $this->input->secret($command->secret, 'secret'),
            ConnectionState::Ok,
            null,
            null,
            $this->staffId(),
        );

        if ($this->connections->exists($store)) {
            throw new AlreadyWired;
        }

        // Outside the transaction: no lock is held while the provider answers.
        $this->adapters->for($provider)->test($connection);

        $this->db->transaction(function () use ($store, $connection): void {
            // Wired by someone else while the provider answered.
            if (! $this->connections->save($connection, new: true)) {
                throw new AlreadyWired;
            }

            $this->reads->queue($store, ReadKind::Now);
            $this->platform->recordAudit(new AuditEntryDto(
                'sync.connection.wired',
                'sync.connection',
                $store->value,
                $store->value,
                AuditChanges::none()
                    ->changed('provider', null, $connection->provider->value)
                    ->changed('url', null, $connection->address)
                    ->changed('settings', null, $connection->settings === [] ? null : $connection->settings),
            ));
        });

        // After the commit, in Inventory's own transactions: its store lock is not held for the whole
        // pass, and the store answers wired to everyone by then. The repair tells Catalog again if this
        // fails.
        $this->stock->wired($store);
    }

    /** The Super Admin who wires it, or whose action the system carries out. */
    private function staffId(): string
    {
        $actor = $this->actors->current();
        $staff = $actor->type === ActorType::Staff ? $actor : ($actor->requestedBy?->type === ActorType::Staff ? $actor->requestedBy : null);

        return $staff->id ?? throw new Unauthorized(self::PERMISSION);
    }
}
