<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Modules\Payments\Application\Attempt\AttemptCharge;
use Modules\Payments\Application\Attempt\AttemptChecks;
use Modules\Payments\Application\Attempt\AttemptPreparation;
use Modules\Payments\Application\Request\OrderPaymentRequests;
use Modules\Payments\Application\Session\OpenPaymentSession;
use Modules\Payments\Application\Settings\StoreBankAccount;
use Modules\Payments\Domain\Repository\Attempts;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Domain\Repository\Transactions;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\BankAccountDto;
use Modules\Payments\Public\Dto\OnlinePaymentDto;
use Modules\Payments\Public\Dto\PaymentDto;
use Modules\Payments\Public\Dto\PaymentRequestDto;
use Modules\Payments\Public\Dto\PaymentRequestStateDto;
use Modules\Payments\Public\Dto\PaymentSessionDto;
use Modules\Payments\Public\Dto\PaymentStartDto;
use Modules\Payments\Public\Dto\PaymentStateDto;
use Modules\Payments\Public\Dto\PaymentTimersDto;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Payments\Public\Enums\OnlinePaymentReason;
use Modules\Payments\Public\Enums\PaymentAudience;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

final readonly class PaymentsApiImpl implements PaymentsApi
{
    public function __construct(
        private Gateways $gateways,
        private PlatformApi $platform,
        private StoreBankAccount $bankAccount,
        private OpenPaymentSession $sessions,
        private AttemptPreparation $preparation,
        private AttemptCharge $charges,
        private AttemptChecks $checks,
        private Transactions $transactions,
        private OrderPaymentRequests $requests,
        private Attempts $attempts,
    ) {}

    /**
     * A company never pays online (sales.md §1.9); a store with no gateway, or one switched off, offers
     * "staff will contact you" (owner, 2026-10-18); MyFatoorah's refusal of the current key stops online
     * payment by itself until a new one is saved (§1.2).
     */
    public function availability(StoreId $store, PaymentAudience $audience): OnlinePaymentDto
    {
        if ($audience === PaymentAudience::Company) {
            return new OnlinePaymentDto(false, null, OnlinePaymentReason::Company);
        }

        $gateway = $this->gateways->of($store->value);

        if ($gateway === null) {
            return new OnlinePaymentDto(false, null, OnlinePaymentReason::NoGateway);
        }

        $key = $this->gateways->currentKey($store->value, $gateway->mode);

        // A gateway is switched on only with a key for its mode, and keys are never taken away: no key
        // here would be a fault, answered as switched off rather than offered without a key.
        if (! $gateway->active || $key === null) {
            return new OnlinePaymentDto(false, null, OnlinePaymentReason::GatewayOff);
        }

        if ($key->rejected) {
            return new OnlinePaymentDto(false, $gateway->mode, OnlinePaymentReason::KeyRejected);
        }

        return new OnlinePaymentDto(true, $gateway->mode, null);
    }

    public function openSession(SessionRequestDto $request): PaymentSessionDto
    {
        return $this->sessions->open($request);
    }

    public function prepare(PayRequestDto $request): string
    {
        return $this->preparation->prepare($request);
    }

    public function charge(string $attemptId): PaymentStartDto
    {
        return $this->charges->charge($attemptId);
    }

    public function abandon(string $attemptId): bool
    {
        return $this->preparation->abandon($attemptId);
    }

    public function refresh(string $ownerId): PaymentStateDto
    {
        return $this->checks->refresh($ownerId);
    }

    public function lastKnown(string $ownerId): PaymentStateDto
    {
        return $this->checks->lastKnown($ownerId);
    }

    public function bankAccount(StoreId $store): ?BankAccountDto
    {
        return $this->bankAccount->for($store);
    }

    public function transferCheckHours(StoreId $store): int
    {
        return $this->platform->setting(PaymentsSettings::TRANSFER_CHECK_HOURS, $store)->int();
    }

    public function inTestMode(StoreId $store): bool
    {
        $gateway = $this->gateways->of($store->value);

        return $gateway !== null && $gateway->active && $gateway->mode === PaymentMode::Test;
    }

    public function timers(StoreId $store): PaymentTimersDto
    {
        return new PaymentTimersDto(
            $this->platform->setting(PaymentsSettings::WINDOW_MINUTES, $store)->int(),
            $this->platform->setting(PaymentsSettings::WAIT_MINUTES, $store)->int(),
            $this->platform->setting(PaymentsSettings::HOLD_MINUTES, $store)->int(),
        );
    }

    public function needsAttention(string $attemptId): void
    {
        $this->preparation->markForAttention($attemptId);
    }

    public function requestPayment(PaymentRequestDto $request, string $staffId): PaymentRequestStateDto
    {
        return $this->requests->request($request, $staffId);
    }

    public function withdraw(string $orderId): bool
    {
        return $this->requests->withdraw($orderId);
    }

    public function withdrawForCancelled(string $orderId): void
    {
        $this->requests->orderCancelled($orderId);
    }

    public function openRequest(string $orderId): ?PaymentRequestStateDto
    {
        return $this->requests->open($orderId);
    }

    public function openRequests(array $orderIds): array
    {
        return $this->requests->openOfOrders($orderIds);
    }

    public function paymentOpen(string $orderId): bool
    {
        return $this->attempts->openOfOrder($orderId) !== null;
    }

    public function successes(string $orderId): array
    {
        return $this->transactions->acceptedOfOrder($orderId);
    }

    public function attempts(string $orderId): array
    {
        return $this->attempts->ofOrder($orderId);
    }

    public function payment(string $paymentId): ?PaymentDto
    {
        return $this->transactions->dto($paymentId);
    }
}
