<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Notifications;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The provider's notice** (sync.md §1.3; the Odoo request's Format B): a list of up to 1,000 codes
 * whose price or stock changed, signed with the store's notification secret - an HMAC-SHA256 of the
 * timestamp, a dot and the body, in hex - and refused when the signature is wrong or the timestamp more
 * than 5 minutes off. A good one only queues a read of those codes: we then read their values from the
 * provider's API, so a forged notice could make us read, never set a value. No staff permission: the
 * signature is its authority.
 */
final readonly class NotificationReceiver
{
    /** How far the notice's clock may be from ours, either way. */
    public const int MOST_SKEW_SECONDS = 300;

    public const int MOST_CODES = 1000;

    private const int MOST_CODE = 255;

    public function __construct(
        private Connections $connections,
        private Reads $reads,
    ) {}

    public function receive(string $storeId, ?string $timestamp, ?string $signature, string $body): NotificationOutcome
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            return NotificationOutcome::Refused;
        }

        $connection = $this->connections->find($store);

        // Not wired, or not signed by its provider: refused the same way, saying nothing of which.
        if ($connection === null || $timestamp === null || $signature === null || preg_match('/\A\d{1,12}\z/', $timestamp) !== 1
            || abs(CarbonImmutable::now()->getTimestamp() - (int) $timestamp) > self::MOST_SKEW_SECONDS
            || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $connection->secret), strtolower(trim($signature)))) {
            return NotificationOutcome::Refused;
        }

        $codes = self::codes($body);

        if ($codes === null) {
            return NotificationOutcome::Invalid;
        }

        $this->reads->queue($store, ReadKind::Notified, $codes);

        return NotificationOutcome::Queued;
    }

    /**
     * @return list<string>|null the codes, trimmed and once each; null when the body is not `{"codes": [...]}` of 1 to 1,000 codes
     */
    private static function codes(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $given = is_array($decoded) ? ($decoded['codes'] ?? null) : null;

        if (! is_array($given) || ! array_is_list($given) || $given === [] || count($given) > self::MOST_CODES) {
            return null;
        }

        $codes = [];

        foreach ($given as $code) {
            $code = is_string($code) ? trim($code) : '';

            if ($code === '' || mb_strlen($code) > self::MOST_CODE) {
                return null;
            }

            $codes[] = $code;
        }

        // Kept as text: as array keys, PHP would turn a code of digits into a number.
        return array_values(array_unique($codes));
    }
}
