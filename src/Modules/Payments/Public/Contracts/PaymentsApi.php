<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Contracts;

use Modules\Payments\Public\Dto\BankAccountDto;
use Modules\Payments\Public\Dto\OnlinePaymentDto;
use Modules\Payments\Public\Dto\PaymentAttemptDto;
use Modules\Payments\Public\Dto\PaymentDto;
use Modules\Payments\Public\Dto\PaymentRequestDto;
use Modules\Payments\Public\Dto\PaymentRequestStateDto;
use Modules\Payments\Public\Dto\PaymentSessionDto;
use Modules\Payments\Public\Dto\PaymentStartDto;
use Modules\Payments\Public\Dto\PaymentStateDto;
use Modules\Payments\Public\Dto\PaymentTimersDto;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Payments\Public\Enums\PaymentAudience;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules ask Payments (payments.md §2.1), built step by step in stage 7: the gateway's
 * availability, the bank account and the transfer check time now (step 8); the session and the attempts now (steps 9, 10); the request
 * with step 12. Each gateway is an
 * adapter behind it: MyFatoorah first. Payments publishes `PaymentSucceeded` / `PaymentFailed`.
 */
interface PaymentsApi
{
    /** Whether this store takes online payment now, for this buyer: live or test, or why not (§1.2, §1.3). */
    public function availability(StoreId $store, PaymentAudience $audience): OnlinePaymentDto;

    /**
     * A session for the form on a checkout or a request page (§1.4 step 0). Nothing is held or charged.
     * Throws the errors of §7: no online payment, the gateway's refusal, or no answer from it.
     */
    public function openSession(SessionRequestDto $request): PaymentSessionDto;

    /**
     * Writes the attempt inside Sales's transaction (§1.4 step 3): the session checked, the key made, the checks
     * booked, with no call to the gateway. Answers the attempt's id.
     * Throws the errors of §7: no online payment, a session not usable, a payment in progress.
     */
    public function prepare(PayRequestDto $request): string;

    /**
     * Charges the prepared attempt, after Sales's commit (§1.4 step 4): claimed first (rule 4). Safe to call again.
     * **Never inside a transaction**: the claim must be committed before the call.
     */
    public function charge(string $attemptId): PaymentStartDto;

    /** Ends a prepared attempt that was never charged; false when the charge was already claimed (rule 15). */
    public function abandon(string $attemptId): bool;

    /**
     * Asks the gateway now about a try's or a request's attempt (§1.4 step 8, §1.7), at most each 10 seconds.
     * **Never inside a transaction**: no lock is held through a call to the gateway.
     */
    public function refresh(string $ownerId): PaymentStateDto;

    /**
     * The account a company of this store transfers to - **null while bank transfer is temporarily off**,
     * until the store has filled in all three settings (§1.9). Checkout then offers paying through staff
     * alone, and the server refuses a transfer.
     */
    public function bankAccount(StoreId $store): ?BankAccountDto;

    /** Whether this store's gateway is on and in test mode: every order placed then is a test order (§1.6). */
    public function inTestMode(StoreId $store): bool;

    /** How long a payment may take (§1.7), for a session that must outlast a Pay at a quote's last second. */
    public function timers(StoreId $store): PaymentTimersDto;

    /** Sales could not settle this attempt's try: shown to staff among those needing attention (§1.7). */
    public function needsAttention(string $attemptId): void;

    /** The hours staff have to check a transfer (§1.9). */
    public function transferCheckHours(StoreId $store): int;

    /**
     * A staff request for the rest of an order (§1.5), open 60 minutes. The same amount while one is open answers
     * it as it is; another amount is `AmountChanged`; a payment open on the order, `PaymentInProgress`.
     */
    public function requestPayment(PaymentRequestDto $request, string $staffId): PaymentRequestStateDto;

    /** Withdraws an order's open request (§1.5): true when none is left open; false while a payment on it is open. */
    public function withdraw(string $orderId): bool;

    /** The order's open request, for its page (§1.5); null when none. One past its time with no payment open is ended here. */
    public function openRequest(string $orderId): ?PaymentRequestStateDto;

    /**
     * The requests open on these orders, for one page of My Orders in one read (§4.2). Writes nothing: one past its
     * time with no payment open is simply left out.
     *
     * @param  list<string>  $orderIds
     * @return array<string, PaymentRequestStateDto> by order id
     */
    public function openRequests(array $orderIds): array;

    /** Whether any payment on this order is open (§1.5): staff's edits, hand recording and the customer's cancel wait. */
    public function paymentOpen(string $orderId): bool;

    /**
     * The attempts on an order, oldest first, for staff's Online Payments block (§4.2).
     *
     * @return list<PaymentAttemptDto>
     */
    public function attempts(string $orderId): array;

    /** One successful (or refused) transaction as Payments recorded it, read when an event arrives with ids only. */
    public function payment(string $paymentId): ?PaymentDto;
}
