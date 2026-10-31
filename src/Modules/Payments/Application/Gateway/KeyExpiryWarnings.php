<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Public\Events\GatewayKeyExpiring;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * The daily look at every current key's expiry date (payments.md §1.2): 30, 7 and 1 days before it, in
 * the store's own day, `GatewayKeyExpiring` once per key and threshold. A key saved with less time left
 * is warned of the nearest threshold only, never of those already behind it (gap 8.7); a date already
 * past warns no more - payment goes on until MyFatoorah refuses the key (gap 8.6).
 */
final readonly class KeyExpiryWarnings
{
    /** Nearest last. */
    public const array THRESHOLDS = [30, 7, 1];

    public function __construct(
        private Gateways $gateways,
        private PlatformApi $platform,
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    /** @return int how many warnings were sent */
    public function send(): int
    {
        $sent = 0;

        foreach ($this->gateways->currentKeys() as $key) {
            $store = $this->platform->store(StoreId::fromString($key->storeId));

            if ($store === null) {
                continue;
            }

            $due = self::due(self::daysLeft($key->expiresOn, $store->timezone));

            if ($due === null || ($key->warnedDays !== null && $key->warnedDays <= $due)) {
                continue;
            }

            $this->db->transaction(function () use ($key, $due): void {
                $this->gateways->markWarned($key->id, $due);
                $this->outbox->record(new GatewayKeyExpiring((string) Str::uuid(), $key->storeId, $key->mode->value, $due, $key->expiresOn->format('Y-m-d'), CarbonImmutable::now()->toDateTimeImmutable()));
            });
            $sent++;
        }

        return $sent;
    }

    /** Whole days from the store's today to the expiry date: 0 on the day itself, below 0 once past. */
    public static function daysLeft(DateTimeInterface $expiresOn, string $timezone): int
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return (int) $today->diffInDays(CarbonImmutable::parse($expiresOn->format('Y-m-d'), $timezone), false);
    }

    /** The nearest threshold the key is within, or null when it is further away or already past its date. */
    public static function due(int $daysLeft): ?int
    {
        if ($daysLeft < 0) {
            return null;
        }

        $due = null;

        foreach (self::THRESHOLDS as $threshold) {
            if ($daysLeft <= $threshold) {
                $due = $threshold;
            }
        }

        return $due;
    }
}
