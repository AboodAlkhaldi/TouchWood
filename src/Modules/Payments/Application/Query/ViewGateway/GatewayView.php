<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ViewGateway;

use Modules\Payments\Application\Gateway\KeyExpiryWarnings;
use Modules\Payments\Domain\Model\GatewayKey;

/**
 * The gateway page's data (payments.md §1.2): whether MyFatoorah is offered in the store's country, set
 * up, on, its mode, and each mode's current key - never more of a key than its last 6 characters.
 * `daysLeft` is counted in the store's own day; the banner shows from 30.
 */
final readonly class GatewayView
{
    /**
     * @param  array{last6: string, expiresOn: string, daysLeft: int, rejected: bool, setAt: string}|null  $testKey
     * @param  array{last6: string, expiresOn: string, daysLeft: int, rejected: bool, setAt: string}|null  $liveKey
     * @param  list<array{domain: string, mode: string}>  $applePayDomains  registered, each for its mode (§1.2)
     * @param  array{TEST: string, LIVE: string}  $noticeAddresses  to paste into each mode's MyFatoorah portal (§1.2)
     */
    public function __construct(
        public bool $offered,
        public bool $setUp,
        public bool $active,
        public ?string $mode,
        public ?array $testKey,
        public ?array $liveKey,
        public array $applePayDomains,
        public array $noticeAddresses,
    ) {}

    /**
     * @return array{last6: string, expiresOn: string, daysLeft: int, rejected: bool, setAt: string}|null
     */
    public static function key(?GatewayKey $key, string $timezone): ?array
    {
        if ($key === null) {
            return null;
        }

        return [
            'last6' => $key->last6,
            'expiresOn' => $key->expiresOn->format('Y-m-d'),
            'daysLeft' => KeyExpiryWarnings::daysLeft($key->expiresOn, $timezone),
            'rejected' => $key->rejected,
            'setAt' => $key->setAt->format(DATE_ATOM),
        ];
    }
}
