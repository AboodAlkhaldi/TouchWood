<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Events\GatewayKeyRejected;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * The key a call speaks with, and what its refusal means (payments.md §1.2).
 */
final readonly class GatewayAccesses
{
    public function __construct(
        private Gateways $gateways,
        private GatewayAddresses $addresses,
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    /** The mode's current key, or null when the mode has none. */
    public function current(StoreDto $store, PaymentMode $mode): ?GatewayAccess
    {
        $key = $this->gateways->currentKey($store->id, $mode);

        return $key === null ? null : new GatewayAccess(
            $store->id,
            $mode,
            $this->addresses->api($store->countryCode, $mode),
            $key->id,
            $this->gateways->readKey($key->id),
        );
    }

    /**
     * MyFatoorah's 401. On the current key online payment stops by itself and the Super Admins are told,
     * once until a new key is set; on an earlier one, kept for old attempts, nothing happens here (§1.2).
     */
    public function refused(GatewayAccess $access): void
    {
        $this->db->transaction(function () use ($access): void {
            $now = CarbonImmutable::now()->toDateTimeImmutable();

            if ($this->gateways->markRejected($access->keyId, $now)) {
                $this->outbox->record(new GatewayKeyRejected((string) Str::uuid(), $access->storeId, $access->mode->value, $now));
            }
        });
    }
}
