<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Session;

use Carbon\CarbonImmutable;
use Modules\Payments\Application\Gateway\GatewayAccesses;
use Modules\Payments\Application\Gateway\GatewayAddresses;
use Modules\Payments\Application\Gateway\GatewayClient;
use Modules\Payments\Application\Gateway\OwnAddresses;
use Modules\Payments\Domain\Exception\GatewayKeyRefused;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\NoOnlinePayment;
use Modules\Payments\Domain\Model\GatewaySession;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Domain\Repository\Sessions;
use Modules\Payments\Public\Dto\PaymentSessionDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * A session for the form on a checkout or a request page (payments.md §1.3, §1.4 step 0): nothing is held
 * or charged. Written down as ours, tied to its owner, buyer and amount, so Pay can check it (rule 7).
 */
final readonly class OpenPaymentSession
{
    public function __construct(
        private PlatformApi $platform,
        private Gateways $gateways,
        private GatewayAccesses $accesses,
        private GatewayAddresses $addresses,
        private OwnAddresses $own,
        private GatewayClient $client,
        private Sessions $sessions,
    ) {}

    /**
     * @throws NoOnlinePayment|InvalidPaymentsInput|GatewayRefused|GatewayUnreachable
     */
    public function open(SessionRequestDto $request): PaymentSessionDto
    {
        $store = $this->platform->store($request->storeId) ?? throw new NoOnlinePayment;
        $gateway = $this->gateways->of($store->id);

        if ($gateway === null || ! $gateway->active || ! $this->addresses->offered($store->countryCode)) {
            throw new NoOnlinePayment;
        }

        if ($this->gateways->currentKey($store->id, $gateway->mode)?->rejected !== false) {
            throw new NoOnlinePayment;
        }

        if ($request->amount->currencyCode !== $store->currencyCode || ! $request->amount->isPositive()) {
            throw new InvalidPaymentsInput('amount', 'more than zero, in the store\'s currency');
        }

        if ($request->expiresAt <= CarbonImmutable::now()) {
            throw new InvalidPaymentsInput('expires_at', 'a time still to come');
        }

        $access = $this->accesses->current($store, $gateway->mode) ?? throw new NoOnlinePayment;

        try {
            $opened = $this->client->openSession($access, $request->amount->toDecimal($store->currencyExponent), $store->currencyCode, $request->locale, $request->expiresAt);
        } catch (GatewayKeyRefused) {
            $this->accesses->refused($access);

            throw new NoOnlinePayment;
        }

        $this->sessions->add(new GatewaySession(
            $opened->id,
            $store->id,
            $gateway->mode,
            $request->ownerId,
            $request->buyerId,
            $request->amount->minorUnits,
            $store->currencyCode,
            $opened->expiresAt,
        ));

        return new PaymentSessionDto(
            $opened->id,
            $this->addresses->script($store->countryCode, $gateway->mode),
            $gateway->mode,
            $opened->expiresAt,
            $gateway->applePayOn($this->own->domain(), $gateway->mode),
        );
    }
}
