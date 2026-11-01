<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Repository;

use DateTimeImmutable;
use Modules\Payments\Domain\Model\Gateway;
use Modules\Payments\Domain\Model\GatewayKey;
use Modules\Payments\Public\Enums\PaymentMode;

/**
 * The stores' gateways and their keys (payments.md §1.2, §5). A key and a secret go in in clear and are
 * kept encrypted; only `readKey` and `secrets` give them back, to speak to the gateway.
 */
interface Gateways
{
    /** The store's gateway's lock, held to the end of the transaction: every change of it takes it. */
    public function lockStore(string $storeId): void;

    public function of(string $storeId): ?Gateway;

    /** @return list<Gateway> every store's gateway that is switched on */
    public function switchedOn(): array;

    public function create(string $storeId, PaymentMode $mode, string $staffId, DateTimeImmutable $now): void;

    public function save(Gateway $gateway, DateTimeImmutable $now): void;

    /** The store's current key in this mode, or null. */
    public function currentKey(string $storeId, PaymentMode $mode): ?GatewayKey;

    /**
     * The new current key of the store and mode; the one before it is retired, kept until its attempts
     * close (rule 14). Answers the new key's id.
     */
    public function addKey(string $storeId, PaymentMode $mode, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $secret, DateTimeImmutable $expiresOn, string $staffId, DateTimeImmutable $now): string;

    /**
     * Every current key, for the daily look at their expiry dates.
     *
     * @return list<GatewayKey>
     */
    public function currentKeys(): array;

    /**
     * Marks the key warned at this threshold - only while it is still current and not yet warned at this
     * one or a nearer one. False when another run, or a new key, came first: then nothing is sent.
     */
    public function markWarned(string $keyId, int $days): bool;

    /** The key in clear, to make a call with (§1.1 rule 14: any key still kept). */
    public function readKey(string $keyId): string;

    /**
     * Every secret still kept for the store and mode, current first, in clear: a notice may be signed by
     * any of them (rule 14).
     *
     * @return list<string>
     */
    public function secrets(string $storeId, PaymentMode $mode): array;

    /**
     * Marks the key refused by the gateway - only while it is current and not yet marked. False otherwise:
     * an earlier key, or another call came first.
     */
    public function markRejected(string $keyId, DateTimeImmutable $now): bool;
}
