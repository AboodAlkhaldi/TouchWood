<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\WithdrawOrderRequest;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderRequests;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\PaymentBeingConfirmed;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;

/** Staff's Withdraw Request (payments.md §1.5, §4.2): refused while a payment on it is open; none open is nothing to do. */
final readonly class WithdrawOrderRequestHandler
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

    /** @throws Unauthorized|OrderNotFound|PaymentBeingConfirmed */
    public function handle(WithdrawOrderRequest $command): void
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);

        $this->db->transaction(function () use ($orderId, $staffId): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;
            $open = $this->payments->openRequest($order->id);

            if ($open === null) {
                return;
            }

            $this->requests->withdrawn($order->id);
            $this->orders->recordHistory($order->id, $order->storeId, 'PAYMENT_REQUEST_WITHDRAWN', null, $staffId, CarbonImmutable::now(), ['requestId' => $open->requestId]);
            $this->platform->recordAudit(new AuditEntryDto('sales.order.payment_request_withdrawn', 'sales.order', $order->id, $order->storeId, AuditChanges::none()
                ->changed('request_id', $open->requestId, null)));
        }, 3);
    }
}
