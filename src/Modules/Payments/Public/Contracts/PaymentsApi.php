<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Contracts;

use Modules\Payments\Public\Dto\BankAccountDto;
use Modules\Payments\Public\Dto\OnlinePaymentDto;
use Modules\Payments\Public\Dto\PaymentDto;
use Modules\Payments\Public\Enums\PaymentAudience;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules ask Payments (payments.md §2.1), built step by step in stage 7: the gateway's
 * availability, the bank account and the transfer check time now (step 8); the session, the charge and
 * the request with the attempts (steps 9-11). Each gateway is an
 * adapter behind it: MyFatoorah first. Payments publishes `PaymentSucceeded` / `PaymentFailed`.
 */
interface PaymentsApi
{
    /** Whether this store takes online payment now, for this buyer: live or test, or why not (§1.2, §1.3). */
    public function availability(StoreId $store, PaymentAudience $audience): OnlinePaymentDto;

    /**
     * The account a company of this store transfers to - **null while bank transfer is temporarily off**,
     * until the store has filled in all three settings (§1.9). Checkout then offers paying through staff
     * alone, and the server refuses a transfer.
     */
    public function bankAccount(StoreId $store): ?BankAccountDto;

    /** The hours staff have to check a transfer (§1.9). */
    public function transferCheckHours(StoreId $store): int;

    /** One successful (or refused) transaction as Payments recorded it - null until the charge is built (step 9). */
    public function payment(string $paymentId): ?PaymentDto;
}
