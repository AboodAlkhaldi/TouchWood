<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use DateTimeImmutable;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\CallerPermission;
use Modules\Loyalty\Public\Dto\SettleOrder;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;

/**
 * What a cancellation and a rejection both do (sales.md §1.6), after the order's own lock: the points
 * used come back with their old dates (`LoyaltyApi::orderCancelled`), then — last, its lock the last
 * taken — what the order holds is freed (`InventoryApi::release`). The coupon's use stays counted
 * (owner, 2026-10-08). And each change's history row, and a staff change's audit entry.
 */
final readonly class OrderUndoing
{
    public function __construct(
        private LoyaltyApi $loyalty,
        private InventoryApi $inventory,
        private Orders $orders,
        private PlatformApi $platform,
    ) {}

    /** Points back, then the stock freed — the Sales permission that allowed it named to Loyalty. */
    public function undo(OrderState $order, string $permission): void
    {
        $this->loyalty->orderCancelled(new SettleOrder($order->id, $order->storeId, new CallerPermission('sales', $permission)));
        $this->inventory->release($order->id);
    }

    /**
     * The change in the order's history; a staff member's audited too.
     *
     * @param  array<string, string|int|bool|null>  $detail  never the customer's email or phone
     */
    public function record(OrderState $order, string $kind, ?string $staffId, DateTimeImmutable $at, string $toStatus, array $detail = []): void
    {
        $this->orders->recordHistory($order->id, $order->storeId, $kind, null, $staffId, $at, $detail);

        if ($staffId !== null) {
            $changes = AuditChanges::none()->changed('status', $order->status, $toStatus);

            foreach ($detail as $attribute => $value) {
                $changes = $changes->changed($attribute, null, $value);
            }

            $this->platform->recordAudit(new AuditEntryDto('sales.order.'.strtolower($kind), 'sales.order', $order->id, $order->storeId, $changes));
        }
    }
}
