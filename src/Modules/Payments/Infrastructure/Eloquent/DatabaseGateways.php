<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Payments\Domain\Model\Gateway;
use Modules\Payments\Domain\Model\GatewayKey;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Public\Enums\PaymentMode;
use stdClass;

final readonly class DatabaseGateways implements Gateways
{
    public function __construct(
        private ConnectionInterface $db,
        private Encrypter $encrypter,
    ) {}

    public function lockStore(string $storeId): void
    {
        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['payments:gateway:'.strtolower($storeId)], false);
    }

    public function of(string $storeId): ?Gateway
    {
        $row = $this->db->table('payments.gateways')->where('store_id', strtolower($storeId))->first();

        return $row instanceof stdClass
            ? new Gateway((string) $row->store_id, (bool) $row->active, PaymentMode::from((string) $row->mode), (bool) $row->key_rejected)
            : null;
    }

    public function create(string $storeId, PaymentMode $mode, string $staffId, DateTimeImmutable $now): void
    {
        $this->db->table('payments.gateways')->insert([
            'store_id' => strtolower($storeId),
            'gateway' => 'MYFATOORAH',
            'active' => false,
            'mode' => $mode->value,
            'key_rejected' => false,
            'apple_pay_domains' => '[]',
            'created_by' => $staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function save(Gateway $gateway, DateTimeImmutable $now): void
    {
        $this->db->table('payments.gateways')->where('store_id', $gateway->storeId)->update([
            'active' => $gateway->active,
            'mode' => $gateway->mode->value,
            'key_rejected' => $gateway->keyRejected,
            'updated_at' => $now,
        ]);
    }

    public function currentKey(string $storeId, PaymentMode $mode): ?GatewayKey
    {
        $row = $this->db->table('payments.gateway_keys')->where('store_id', strtolower($storeId))->where('mode', $mode->value)->where('current', true)->first();

        return $row instanceof stdClass ? self::key($row) : null;
    }

    public function addKey(string $storeId, PaymentMode $mode, string $key, string $secret, DateTimeImmutable $expiresOn, string $staffId, DateTimeImmutable $now): string
    {
        $this->db->table('payments.gateway_keys')->where('store_id', strtolower($storeId))->where('mode', $mode->value)->where('current', true)
            ->update(['current' => false, 'retired_at' => $now]);

        $id = strtolower((string) Str::ulid());
        $this->db->table('payments.gateway_keys')->insert([
            'id' => $id,
            'store_id' => strtolower($storeId),
            'mode' => $mode->value,
            'key_encrypted' => $this->encrypter->encryptString($key),
            'key_last6' => substr($key, -6),
            'key_expires_on' => $expiresOn->format('Y-m-d'),
            'secret_encrypted' => $this->encrypter->encryptString($secret),
            'current' => true,
            'set_by' => $staffId,
            'set_at' => $now,
        ]);

        return $id;
    }

    public function currentKeys(): array
    {
        $keys = [];

        foreach ($this->db->table('payments.gateway_keys')->where('current', true)->orderBy('id')->get() as $row) {
            $keys[] = self::key($row);
        }

        return $keys;
    }

    public function markWarned(string $keyId, int $days): void
    {
        $this->db->table('payments.gateway_keys')->where('id', $keyId)->update(['warned_days' => $days]);
    }

    private static function key(stdClass $row): GatewayKey
    {
        return new GatewayKey(
            (string) $row->id,
            (string) $row->store_id,
            PaymentMode::from((string) $row->mode),
            (string) $row->key_last6,
            new CarbonImmutable((string) $row->key_expires_on),
            $row->warned_days === null ? null : (int) $row->warned_days,
            new CarbonImmutable((string) $row->set_at),
        );
    }
}
