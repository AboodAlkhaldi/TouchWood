<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Checkout;

use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentStateDto;
use Modules\Payments\Public\Enums\PaymentState;
use Modules\Sales\Domain\Model\CheckoutTry;

/**
 * A try acted on as soon as its answer is known, without waiting for the outbox (§9.5 #4): the page's answer, and
 * the hourly look. **Never inside a transaction**: it asks Payments, which may call the gateway.
 */
final readonly class TrySettling
{
    public function __construct(
        private PaymentsApi $payments,
        private TryCompletion $completion,
        private TryRelease $release,
    ) {}

    public function settle(CheckoutTry $try): PaymentStateDto
    {
        $state = $this->payments->refresh($try->id);

        if ($try->isLive()) {
            $this->act($try, $state);
        }

        return $state;
    }

    /** As `settle` on what is already known, the gateway not asked: a page load must not wait on it (payments.md §1.7). */
    public function settleKnown(CheckoutTry $try): PaymentStateDto
    {
        $state = $this->payments->lastKnown($try->id);

        if ($try->isLive()) {
            $this->act($try, $state);
        }

        return $state;
    }

    /** What an answer already in hand means for a live try: `PayOnline`'s charge, refused at once. */
    public function act(CheckoutTry $try, PaymentStateDto $state): void
    {
        match ($state->state) {
            PaymentState::Succeeded => $state->paymentId === null ? null : $this->completion->complete($try->id, $state->paymentId),
            PaymentState::Failed, PaymentState::Expired => $this->release->release($try->id),
            PaymentState::NotAccepted => $this->release->notAccepted($try->id),
            default => null,
        };
    }
}
