<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Checkout;

use Carbon\CarbonImmutable;
use Modules\Payments\Public\Dto\PaymentStateDto;
use Modules\Sales\Domain\Exception\CheckoutTryNotFound;
use Modules\Sales\Domain\Model\CheckoutTry;
use Modules\Sales\Domain\Repository\CheckoutTries;
use Modules\Sales\Domain\Repository\Orders;

/** The try as it now stands, read again after acting on it. */
final readonly class TryPage
{
    public function __construct(
        private CheckoutTries $tries,
        private Orders $orders,
    ) {}

    public function view(string $tryId, PaymentStateDto $payment): CheckoutTryView
    {
        $try = $this->tries->find($tryId) ?? throw new CheckoutTryNotFound;
        $firstSeen = ! $try->isLive() && $this->tries->markSeen($try->id, CarbonImmutable::now());

        return new CheckoutTryView(
            $try->id, $try->state, $payment->state,
            $try->state === CheckoutTry::PAID ? $this->orders->placedFrom($try->quoteId)?->number : null,
            $payment->windowEndsAt, $payment->waitEndsAt, $payment->holdEndsAt, $payment->nothingCharged,
            $try->refundDue !== null,
            $payment->declineCode,
            $firstSeen,
        );
    }
}
