<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\SetShippingFee;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderInputs;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotEditable;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderShipping;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Public\Events\OrderEdited;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * Staff set the fee of a "shipping fee to be set" order (sales.md §9.5 #12; gap 6.2) — **an edit of its
 * own**, with its reason and its row: the order waiting or approved and not shipped, **whatever is ticked
 * "reduced in the provider"** (nothing it holds changes) — **usable until it ships** (gap 6.2), so a fee set
 * wrongly is set again — for an order with no carrier's fee: one placed where no carrier served, whose fee is
 * to be set or was set by staff (an order with a carrier's quoted fee: `InvalidOrderChange`). The fee is
 * final — no free shipping applies to it (shipping.md §9.3 #11) — and carries no VAT (§9.5 #1): the total
 * moves by the difference and what is due follows; a payment recorded before stays. Its carrier is picked when it ships. Under the order's lock; `OrderEdited`; audited.
 */
final readonly class SetShippingFeeHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_EDIT;

    /** The most a fee may be — as a carrier's price (shipping.md §9.6 #5). */
    public const int MOST = 100_000_000;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private PlatformApi $platform,
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    /**
     * @return string the edit's id
     *
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|NotEditable|InvalidOrderChange
     */
    public function handle(SetShippingFee $command): string
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);
        $reason = OrderInputs::words($command->reason);

        if ($command->amountMinor < 0 || $command->amountMinor > self::MOST) {
            throw new InvalidSalesAttribute('shipping_fee', 'from 0 to '.self::MOST.' in the smallest unit');
        }

        return $this->db->transaction(function () use ($orderId, $staffId, $reason, $command): string {
            $state = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if (! in_array($state->status, [OrderState::PENDING_APPROVAL, OrderState::APPROVED], true) || $state->shipmentStatus !== OrderState::NOT_SHIPPED) {
                throw new NotEditable;
            }

            $order = $this->orders->forEdit($state->id) ?? throw new OrderNotFound;

            if ($order->shipping->carrierId !== null) {
                throw new InvalidOrderChange;
            }

            $fee = $command->amountMinor;
            $before = $order->shipping->pending ? null : $order->shipping->fee;
            // The fee to be set counts 0: the total moves by the difference.
            $total = $order->orderTotal - $order->shipping->fee + $fee;
            $editId = strtolower((string) Str::ulid());
            $now = CarbonImmutable::now();

            $this->orders->addEdit($editId, $order->id, $order->storeId, $reason, $staffId, $now,
                ['shippingFee' => $before, 'orderTotal' => $order->orderTotal], ['shippingFee' => $fee, 'orderTotal' => $total]);
            $this->orders->setShippingFee($order->id, new OrderShipping($fee, $fee, null, null, false, null, null, null), $total);
            $this->outbox->record(new OrderEdited((string) Str::uuid(), $editId, $order->id, $order->storeId, $order->customerId, $now));
            $this->orders->recordHistory($order->id, $order->storeId, 'EDITED', $editId, $staffId, $now, ['orderTotal' => $total, 'shippingFee' => $fee]);
            $this->platform->recordAudit(new AuditEntryDto('sales.order.shipping_fee_set', 'sales.order', $order->id, $order->storeId, AuditChanges::none()
                ->changed('edit_id', null, $editId)->changed('shipping_fee_minor', $before, $fee)->changed('order_total_minor', $order->orderTotal, $total)->personal('reason')));

            return $editId;
        }, 3);
    }
}
