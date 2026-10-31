<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Command\ChangeKeys;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Payments\Application\Gateway\GatewayAddresses;
use Modules\Payments\Application\Gateway\GatewayChange;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Application\PaymentsAudit;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Domain\Exception\GatewayNotOffered;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;
use Modules\Payments\Domain\Exception\KeyTestFailed;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Public\Enums\PaymentMode;
use Shared\Application\Unauthorized;

/**
 * **Saving a mode's key** (payments.md §1.2): tested with MyFatoorah first, at the mode's fixed address for
 * the store's country, before any lock is taken - nothing is saved when a call fails, and the missing
 * permission is named. Saved, it is the mode's current key (the one before it retired, kept for its
 * attempts - rule 14), encrypted, and no longer refused. Audited as "replaced" and by its last 6
 * characters - never the key or the secret.
 */
final readonly class ChangeKeysHandler
{
    public const string PERMISSION = PaymentsPermissions::GATEWAY_MANAGE;

    /** Far longer than any MyFatoorah key; a guard against a pasted page, not a format check. */
    private const int MOST_CHARACTERS = 4000;

    /** The shortest key or secret taken: the last 6 characters are what is shown. */
    private const int FEWEST_CHARACTERS = 6;

    public function __construct(
        private GatewayChange $change,
        private Gateways $gateways,
        private KeyTester $tester,
    ) {}

    /**
     * @throws Unauthorized|InvalidPaymentsInput|GatewayNotOffered|KeyPermissionMissing|KeyTestFailed|GatewayUnreachable
     */
    public function handle(ChangeKeys $command): void
    {
        [$store, $staffId] = $this->change->authorize($command->storeId);

        if (! GatewayAddresses::offered($store->countryCode)) {
            throw new GatewayNotOffered($store->countryCode);
        }

        $mode = PaymentMode::tryFrom(strtoupper(trim($command->mode))) ?? throw new InvalidPaymentsInput('mode', 'TEST or LIVE');
        $key = self::secret($command->key, 'key');
        $secret = self::secret($command->secret, 'secret');
        $expiresOn = self::expiry($command->expiresOn, $store->timezone);

        $this->tester->test(GatewayAddresses::api($store->countryCode, $mode), $key, $store->currencyCode);

        $this->change->run($store->id, function () use ($store, $staffId, $mode, $key, $secret, $expiresOn): array {
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $entries = [];

            if ($this->gateways->of($store->id) === null) {
                $this->gateways->create($store->id, $mode, $staffId, $now);
                $entries[] = PaymentsAudit::entry('gateway', 'set_up', $store->id, $store->id, [], ['mode' => $mode->value, 'active' => 'off']);
            }

            $before = $this->gateways->currentKey($store->id, $mode);
            $this->gateways->addKey($store->id, $mode, $key, $secret, $expiresOn, $staffId, $now);

            $entries[] = PaymentsAudit::keyChanged($store->id, $mode, $before?->last6, substr($key, -6), $before?->expiresOn->format('Y-m-d'), $expiresOn->format('Y-m-d'));

            return [null, $entries];
        });
    }

    /**
     * @throws InvalidPaymentsInput
     */
    private static function secret(#[\SensitiveParameter] string $value, string $field): string
    {
        $value = trim($value);
        $length = mb_strlen($value);

        if ($length < self::FEWEST_CHARACTERS || $length > self::MOST_CHARACTERS || preg_match('/\s/u', $value) === 1) {
            throw new InvalidPaymentsInput($field, 'a key as MyFatoorah gives it');
        }

        return $value;
    }

    /**
     * Required, a date, and not already past in the store's own day (owner, 2026-10-29, gap 8.7). It only
     * warns: payment stops on MyFatoorah's refusal, never on this date (gap 8.6).
     *
     * @throws InvalidPaymentsInput
     */
    private static function expiry(string $value, string $timezone): DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidPaymentsInput('expires_on', 'a date');
        }

        if ($value < CarbonImmutable::now($timezone)->format('Y-m-d')) {
            throw new InvalidPaymentsInput('expires_on', 'not past');
        }

        return $date;
    }
}
