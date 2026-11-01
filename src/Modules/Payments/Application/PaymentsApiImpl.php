<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Modules\Payments\Application\Session\OpenPaymentSession;
use Modules\Payments\Application\Settings\StoreBankAccount;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\BankAccountDto;
use Modules\Payments\Public\Dto\OnlinePaymentDto;
use Modules\Payments\Public\Dto\PaymentDto;
use Modules\Payments\Public\Dto\PaymentSessionDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Payments\Public\Enums\OnlinePaymentReason;
use Modules\Payments\Public\Enums\PaymentAudience;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

final readonly class PaymentsApiImpl implements PaymentsApi
{
    public function __construct(
        private Gateways $gateways,
        private PlatformApi $platform,
        private StoreBankAccount $bankAccount,
        private OpenPaymentSession $sessions,
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

    public function bankAccount(StoreId $store): ?BankAccountDto
    {
        return $this->bankAccount->for($store);
    }

    public function transferCheckHours(StoreId $store): int
    {
        return $this->platform->setting(PaymentsSettings::TRANSFER_CHECK_HOURS, $store)->int();
    }

    public function payment(string $paymentId): ?PaymentDto
    {
        return null;
    }
}
