<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\ValueObject\ConnectionState;
use Modules\Sync\Domain\ValueObject\ProviderKind;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * `sync.connections`: the key and the notification secret encrypted with the app's key (owner,
 * 2026-10-13), decrypted only into the connection a read or a test uses.
 */
final readonly class DatabaseConnections implements Connections
{
    private const string TABLE = 'sync.connections';

    public function __construct(
        private ConnectionInterface $db,
        private Encrypter $encrypter,
    ) {}

    public function find(StoreId $store): ?Connection
    {
        return $this->row($this->db->table(self::TABLE)->where('store_id', $store->value)->first());
    }

    public function lockFor(StoreId $store): ?Connection
    {
        return $this->row($this->db->table(self::TABLE)->where('store_id', $store->value)->lockForUpdate()->first());
    }

    public function exists(StoreId $store): bool
    {
        return $this->db->table(self::TABLE)->where('store_id', $store->value)->exists();
    }

    public function save(Connection $connection, bool $new): bool
    {
        $now = CarbonImmutable::now();
        $columns = [
            'provider' => $connection->provider->value,
            'address' => $connection->address,
            'settings' => json_encode((object) $connection->settings, JSON_THROW_ON_ERROR),
            'key_encrypted' => $this->encrypter->encryptString($connection->key),
            'secret_encrypted' => $this->encrypter->encryptString($connection->secret),
            'updated_at' => $now,
        ];

        // A change writes only what the form holds: the state and the last read are the reads'.
        if (! $new) {
            $this->db->table(self::TABLE)->where('store_id', $connection->storeId)->update($columns);

            return true;
        }

        try {
            $this->db->table(self::TABLE)->insert([
                ...$columns,
                'state' => $connection->state->value,
                'last_read_at' => $connection->lastReadAt,
                'last_error' => $connection->lastError,
                'store_id' => $connection->storeId,
                'created_by' => $connection->createdBy,
                'created_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function markRead(StoreId $store, bool $full): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::TABLE)->where('store_id', $store->value)->update([
            'state' => 'OK',
            'last_error' => null,
            'last_read_at' => $now,
            ...($full ? ['last_full_read_at' => $now] : []),
        ]);
    }

    public function markFailing(StoreId $store, string $why): void
    {
        $this->db->table(self::TABLE)->where('store_id', $store->value)->update([
            'state' => 'FAILING',
            'last_error' => mb_substr($why, 0, 1000),
        ]);
    }

    public function delete(StoreId $store): void
    {
        $this->db->table(self::TABLE)->where('store_id', $store->value)->delete();
    }

    private function row(mixed $row): ?Connection
    {
        if (! $row instanceof stdClass) {
            return null;
        }

        /** @var array<string, string> $settings */
        $settings = json_decode((string) $row->settings, true, flags: JSON_THROW_ON_ERROR);

        return new Connection(
            (string) $row->store_id,
            ProviderKind::from((string) $row->provider),
            (string) $row->address,
            $settings,
            $this->encrypter->decryptString((string) $row->key_encrypted),
            $this->encrypter->decryptString((string) $row->secret_encrypted),
            ConnectionState::from((string) $row->state),
            $row->last_read_at === null ? null : CarbonImmutable::parse((string) $row->last_read_at)->toDateTimeImmutable(),
            $row->last_error === null ? null : (string) $row->last_error,
            (string) $row->created_by,
            CarbonImmutable::parse((string) $row->updated_at)->toDateTimeImmutable(),
        );
    }
}
