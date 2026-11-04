<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RequestOrderPayment;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentRequestDto;
use Modules\Payments\Public\Dto\PaymentRequestStateDto;
use Modules\Payments\Public\Enums\PaymentAudience;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderRequests;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\PaymentBeingConfirmed;
use Modules\Sales\Domain\Exception\PaymentRequestNotAllowed;
use Modules\Sales\Domain\Exception\ShippingFeeNotSet;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\Money;

/**
 * Staff ask the customer to pay **exactly what is missing** online (payments.md §1.5): an individual's order, its
 * fee set, in a store whose gateway is on and in the order's own mode, with no payment open on it. The same amount
 * while a request is open answers it as it is; one of another amount is withdrawn first. The order's lock first,
 * then Payments'.
 */
final readonly class RequestOrderPaymentHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_EDIT;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderRequests $requests,
        private PaymentsApi $payments,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|OrderNotFound|PaymentRequestNotAllowed|ShippingFeeNotSet|InvalidOrderChange|PaymentBeingConfirmed|DomainError
     */
    public function handle(RequestOrderPayment $command): PaymentRequestStateDto
    {
        [$orderId, $store, $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);

        return $this->db->transaction(function () use ($orderId, $store, $staffId): PaymentRequestStateDto {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if ($order->audience !== OrderState::AUDIENCE_PUBLIC || in_array($order->status, [OrderState::CANCELLED, OrderState::REJECTED, OrderState::UNDELIVERED], true)) {
                throw new PaymentRequestNotAllowed;
            }

            if ($order->shippingFeePending) {
                throw new ShippingFeeNotSet;
            }

            $missing = $order->due - $order->held();

            if ($missing <= 0) {
                throw new InvalidOrderChange;
            }

            $this->requests->free($order->id);
            $mode = $order->isTest ? PaymentMode::Test : PaymentMode::Live;
            $gateway = $this->payments->availability($store, PaymentAudience::Individual);

            if (! $gateway->available || $gateway->mode !== $mode) {
                throw new PaymentRequestNotAllowed;
            }

            $open = $this->payments->openRequest($order->id);

            if ($open !== null && $open->amount->minorUnits === $missing) {
                return $open;
            }

            if ($open !== null) {
                $this->requests->withdrawn($order->id);
            }

            $amount = Money::of($missing, $order->currencyCode);
            $request = $this->payments->requestPayment(new PaymentRequestDto($order->id, $order->number, $store, $amount, $order->customerId, $mode), $staffId);
            $now = CarbonImmutable::now();
            $this->orders->recordHistory($order->id, $order->storeId, 'PAYMENT_REQUESTED', null, $staffId, $now, ['requestId' => $request->requestId, 'amount' => $missing]);
            $this->platform->recordAudit(new AuditEntryDto('sales.order.payment_requested', 'sales.order', $order->id, $order->storeId, AuditChanges::none()
                ->changed('request_id', null, $request->requestId)->changed('amount_minor', null, $missing)));

            return $request;
        }, 3);
    }
}
