<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\FollowRequestPayment;

use LogicException;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentAttemptDto;
use Modules\Payments\Public\Enums\PaymentState;
use Modules\Sales\Application\Order\OnlineMoney;
use Modules\Sales\Application\Order\OwnRequestOrder;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Shared\Application\Unauthorized;

/**
 * Pay's answer on a request's page: Payments asked first, and a success recorded at once, without waiting for the
 * outbox. Once either way: the money is keyed by Payments' transaction (§9.5 #7). **Never inside a transaction**: it
 * may call the gateway.
 */
final readonly class FollowRequestPaymentHandler
{
    public function __construct(
        private OwnRequestOrder $own,
        private PaymentsApi $payments,
        private OnlineMoney $money,
    ) {}

    /** @throws Unauthorized|InvalidSalesAttribute|OrderNotFound */
    public function handle(FollowRequestPayment $command): RequestPaymentView
    {
        [$orderId] = $this->own->of($command->storeId, $command->number);
        $requestId = strtolower(trim($command->requestId));
        $paid = array_filter($this->payments->attempts($orderId), static fn (PaymentAttemptDto $attempt): bool => $attempt->ownerId === $requestId);

        if ($paid === []) {
            throw new OrderNotFound;
        }

        $state = $this->payments->refresh($requestId);

        if ($state->state === PaymentState::Succeeded && $state->paymentId !== null) {
            $this->money->record($orderId, $this->payments->payment($state->paymentId) ?? throw new LogicException("No payment {$state->paymentId} of the gateway's."), tell: true);
        }

        return new RequestPaymentView($requestId, $state->state, $state->windowEndsAt, $state->waitEndsAt, $state->holdEndsAt, $state->nothingCharged, $state->declineCode);
    }
}
