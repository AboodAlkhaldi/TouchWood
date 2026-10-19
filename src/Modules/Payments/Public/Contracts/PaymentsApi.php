<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Contracts;

use Modules\Payments\Public\Dto\PaymentDto;
use Modules\Payments\Public\Dto\PaymentMethodDto;
use Modules\Payments\Public\Dto\PaymentStartDto;
use Modules\Payments\Public\Enums\PaymentAudience;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Sales asks Payments (sales.md §2.4) — **declared by Sales for stage 7 to fill, provisional**.
 * Each gateway is an adapter behind it: MyFatoorah first; Tamara and Tabby, for individuals only, and
 * an Egypt gateway later (owner, 2026-10-19, answer 38). Payments takes an order id and an amount,
 * nothing more (handoff §4.4: Payments → Platform), and publishes `PaymentSucceeded` / `PaymentFailed`.
 * Not bound until stage 7: until then no online method exists (§1.9).
 */
interface PaymentsApi
{
    /**
     * The online methods offered in the store for this buyer and amount.
     *
     * @return list<PaymentMethodDto>
     */
    public function methods(StoreId $store, PaymentAudience $audience, Money $amount): array;

    /** Starts paying this amount of an order online, by a method `methods` offered: where to send the buyer. */
    public function start(string $orderId, string $method, Money $amount): PaymentStartDto;

    /** A payment as Payments recorded it — read by Sales when `PaymentSucceeded` arrives with ids only. */
    public function payment(string $paymentId): ?PaymentDto;
}
