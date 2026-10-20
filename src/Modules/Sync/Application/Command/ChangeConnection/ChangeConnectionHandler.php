<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\ChangeConnection;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sync\Application\Connections\ConnectionInput;
use Modules\Sync\Application\Connections\ConnectionStore;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\NotWired;
use Modules\Sync\Domain\Exception\StoreOff;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\Provider\ProviderAdapters;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ProviderKind;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **Changing a store's connection** (sync.md §1.2): the same form, tested the same way before anything
 * is saved; a key or a secret left empty is kept - **unless the address changes**: then both are typed
 * again, so the stored ones are never sent to a new address (owner, 2026-10-20). Saved, a full read is queued, and the change audited -
 * a new key or secret only as replaced, never its value. Only what the form holds is written: the
 * connection's state and its last read stay the reads' (step 4).
 */
final readonly class ChangeConnectionHandler
{
    public const string PERMISSION = SyncPermissions::CONNECTION_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private ConnectionStore $stores,
        private PlatformApi $platform,
        private ConnectionInput $input,
        private ProviderAdapters $adapters,
        private Connections $connections,
        private Reads $reads,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput|StoreOff|NotWired|ConnectionFailed
     */
    public function handle(ChangeConnection $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        // A store that exists; off, only for whoever may switch stores (Q1).
        $store = $this->stores->of($command->storeId);

        $provider = $this->input->provider($command->provider);
        $address = $this->input->address($provider, $command->address);
        $settings = $this->input->settings($provider, $command->settings);
        $key = $command->key === null || $command->key === '' ? null : $this->input->secret($command->key, 'key');
        $secret = $command->secret === null || $command->secret === '' ? null : $this->input->secret($command->secret, 'secret');

        // Tested before any lock is taken, so nothing waits while the provider answers - and never with the
        // stored key or secret at an address they were not given for.
        $stored = $this->connections->find($store) ?? throw new NotWired;
        self::typedAgainForNewAddress($stored, $address, $key, $secret);
        $tested = $this->changed($stored, $provider, $address, $settings, $key, $secret);
        $this->adapters->for($provider)->test($tested);

        $this->db->transaction(function () use ($store, $provider, $address, $settings, $key, $secret, $tested): void {
            // The row as it is now: unwired meanwhile, or changed by someone else, is seen here.
            $before = $this->connections->lockFor($store) ?? throw new NotWired;
            // Its address changed by someone else meanwhile: the same rule against the row as it is now.
            self::typedAgainForNewAddress($before, $address, $key, $secret);
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
     * A new address - as stored, its closing slash trimmed - takes a key and a secret typed again: each left
     * empty is refused under its own box, before any call (owner, 2026-10-20).
     *
     * @throws InvalidSyncInput
     */
    private static function typedAgainForNewAddress(Connection $stored, string $address, #[\SensitiveParameter] ?string $key, #[\SensitiveParameter] ?string $secret): void
    {
        if ($stored->address === $address) {
            return;
        }

        if ($key === null) {
            throw new InvalidSyncInput('key', rule: InvalidSyncInput::KEY_AGAIN);
        }

        if ($secret === null) {
            throw new InvalidSyncInput('secret', rule: InvalidSyncInput::SECRET_AGAIN);
        }
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
