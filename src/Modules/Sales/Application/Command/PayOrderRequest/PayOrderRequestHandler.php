<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PayOrderRequest;

use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Modules\Sales\Application\Command\PayOnline\PayOnlineHandler;
use Modules\Sales\Application\Order\OrderContact;
use Modules\Sales\Application\Order\OrderRequests;
use Modules\Sales\Application\Order\OwnRequestOrder;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\PaymentLinkEnded;
use Modules\Sales\Domain\Exception\TooManyPaymentTries;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * Pay on a request's page (payments.md §1.5): **no cart, no checkout, nothing reserved**. The attempt written under
 * the order's lock, its amount from the request, never the page (rule 12), and only while the request still asks
 * what the order is missing. **After the commit**, the charge. Declined or out of time, the request stays open
 * while its 60 minutes last. A second window: Payments' `PaymentInProgress`.
 */
final readonly class PayOrderRequestHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_MANAGE_OWN;

    /** 10 per order per 10 minutes, and PayOnline's 30 per customer per hour, shared with it (payments.md §1.8). */
    public const int MOST_PER_ORDER = 10;

    public function __construct(
        private OwnRequestOrder $own,
        private Orders $orders,
        private OrderRequests $requests,
        private AccessApi $access,
        private PaymentsApi $payments,
        private RateLimiter $limiter,
        private ConnectionInterface $db,
    ) {}

    /** @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|PaymentLinkEnded|TooManyPaymentTries|DomainError */
    public function handle(PayOrderRequest $command): RequestStart
    {
        [$orderId, $store, $customerId] = $this->own->authorize(self::PERMISSION, $command->storeId, $command->number);
        $sessionId = trim($command->sessionId);

        if ($sessionId === '' || strlen($sessionId) > 100) {
            throw new InvalidSalesAttribute('session', 'a payment session');
        }

        $this->limit($orderId, $customerId);

        [$requestId, $attemptId] = $this->db->transaction(fn (): array => $this->prepare($orderId, $store, $customerId, $sessionId, $command), 3);

        return new RequestStart($requestId, $this->payments->charge($attemptId));
    }

    /** @throws TooManyPaymentTries */
    private function limit(string $orderId, string $customerId): void
    {
        foreach ([["sales:pay:order:{$orderId}", self::MOST_PER_ORDER, 600], ["sales:pay:customer:{$customerId}", PayOnlineHandler::MOST_PER_CUSTOMER, 3600]] as [$key, $most, $seconds]) {
            if ($this->limiter->tooManyAttempts($key, $most)) {
                throw new TooManyPaymentTries($this->limiter->availableIn($key));
            }

            $this->limiter->hit($key, $seconds);
        }
    }

    /**
     * @return array{string, string} the request and the attempt
     *
     * @throws OrderNotFound|PaymentLinkEnded|DomainError
     */
    private function prepare(string $orderId, StoreId $store, string $customerId, string $sessionId, PayOrderRequest $command): array
    {
        $order = $this->orders->lock($orderId);

        if ($order === null || $order->customerId !== $customerId) {
            throw new OrderNotFound;
        }

        $request = $this->requests->live($order->id, $order->due - $order->held()) ?? throw new PaymentLinkEnded;
        $customer = $this->access->customer($customerId) ?? throw new OrderNotFound;
        $contact = OrderContact::of($customer);

        return [$request->requestId, $this->payments->prepare(new PayRequestDto(
            $request->requestId, PaymentOwnerKind::Request, $order->id, null, $store, $customerId, $sessionId, $request->amount,
            $contact->name, $contact->email, $contact->phone, $command->locale, $command->returnUrl,
        ))];
    }
}
