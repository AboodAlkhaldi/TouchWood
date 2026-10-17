<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\ChangeConnection;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sync\Application\Connections\ConnectionInput;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\NotWired;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\Provider\ProviderAdapters;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Changing a store's connection** (sync.md §1.2): the same form, tested the same way before anything
 * is saved; a key or a secret left empty is kept. Saved, a full read is queued, and the change audited -
 * a new key or secret only as replaced, never its value.
 */
final readonly class ChangeConnectionHandler
{
    public const string PERMISSION = SyncPermissions::CONNECTION_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private ConnectionInput $input,
        private ProviderAdapters $adapters,
        private Connections $connections,
        private Reads $reads,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput|NotWired|ConnectionFailed
     */
    public function handle(ChangeConnection $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidSyncInput('store');
        }

        $before = $this->connections->find($store) ?? throw new NotWired;
        $provider = $this->input->provider($command->provider);
        $after = new Connection(
            $before->storeId,
            $provider,
            $this->input->address($command->address),
            $this->input->settings($provider, $command->settings),
            $command->key === null || $command->key === '' ? $before->key : $this->input->secret($command->key, 'key'),
            $command->secret === null || $command->secret === '' ? $before->secret : $this->input->secret($command->secret, 'secret'),
            $before->state,
            $before->lastReadAt,
            $before->lastError,
            $before->createdBy,
        );

        $this->adapters->for($provider)->test($after);

        $this->db->transaction(function () use ($store, $before, $after): void {
            // Unwired while the provider answered: nothing left to change.
            $this->connections->lockFor($store) ?? throw new NotWired;
            $this->connections->save($after, new: false);
            $this->reads->queue($store, ReadKind::Now);

            $changes = AuditChanges::none();
            $fields = [
                'provider' => [$before->provider->value, $after->provider->value],
                'url' => [$before->address, $after->address],
                'settings' => [$before->settings, $after->settings],
            ];

            foreach ($fields as $field => [$from, $to]) {
                if ($from !== $to) {
                    $changes->changed($field, $from, $to);
                }
            }

            if ($before->key !== $after->key) {
                $changes->changed('key', null, 'replaced');
            }

            if ($before->secret !== $after->secret) {
                $changes->changed('secret', null, 'replaced');
            }

            $this->platform->recordAudit(new AuditEntryDto('sync.connection.changed', 'sync.connection', $store->value, $store->value, $changes));
        });
    }
}
