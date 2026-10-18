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
use Modules\Sync\Domain\ValueObject\ProviderKind;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Changing a store's connection** (sync.md §1.2): the same form, tested the same way before anything
 * is saved; a key or a secret left empty is kept. Saved, a full read is queued, and the change audited -
 * a new key or secret only as replaced, never its value. Only what the form holds is written: the
 * connection's state and its last read stay the reads' (step 4).
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

        if ($this->platform->store($store) === null) {
            throw new InvalidSyncInput('store');
        }

        $provider = $this->input->provider($command->provider);
        $address = $this->input->address($command->address);
        $settings = $this->input->settings($provider, $command->settings);
        $key = $command->key === null || $command->key === '' ? null : $this->input->secret($command->key, 'key');
        $secret = $command->secret === null || $command->secret === '' ? null : $this->input->secret($command->secret, 'secret');

        // Tested before any lock is taken, so nothing waits while the provider answers.
        $tested = $this->changed($this->connections->find($store) ?? throw new NotWired, $provider, $address, $settings, $key, $secret);
        $this->adapters->for($provider)->test($tested);

        $this->db->transaction(function () use ($store, $provider, $address, $settings, $key, $secret, $tested): void {
            // The row as it is now: unwired meanwhile, or changed by someone else, is seen here.
            $before = $this->connections->lockFor($store) ?? throw new NotWired;
            $after = $this->changed($before, $provider, $address, $settings, $key, $secret);

            // Another change landed while this one was tested: what is saved is tested again.
            if ($after->key !== $tested->key || $after->secret !== $tested->secret) {
                $this->adapters->for($provider)->test($after);
            }

            $this->connections->save($after, new: false);
            $this->reads->queue($store, ReadKind::Now);
            $this->platform->recordAudit(new AuditEntryDto('sync.connection.changed', 'sync.connection', $store->value, $store->value, self::changes($before, $after)));
        });
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function changed(Connection $before, ProviderKind $provider, string $address, array $settings, ?string $key, ?string $secret): Connection
    {
        return new Connection(
            $before->storeId,
            $provider,
            $address,
            $settings,
            $key ?? $before->key,
            $secret ?? $before->secret,
            $before->state,
            $before->lastReadAt,
            $before->lastError,
            $before->createdBy,
        );
    }

    private static function changes(Connection $before, Connection $after): AuditChanges
    {
        $changes = AuditChanges::none();
        $beforeSettings = $before->settings;
        $afterSettings = $after->settings;
        // Stored as jsonb, which keeps its own key order.
        ksort($beforeSettings);
        ksort($afterSettings);
        $fields = [
            'provider' => [$before->provider->value, $after->provider->value],
            // "url": the audit log takes an "address" for a person's.
            'url' => [$before->address, $after->address],
            'settings' => [$beforeSettings, $afterSettings],
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

        return $changes;
    }
}
