<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\MarkCameBack;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\CallerPermission;
use Modules\Loyalty\Public\Dto\SettleOrder;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Public\Events\OrderCameBack;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Outbox\Outbox;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Staff mark a shipped parcel **came back** once it is at the warehouse — refused at the door, a wrong
 * address, lost and returned — or lost for good, with nothing to put back (sales.md §1.5). It is settled
 * as **a whole return of an order never delivered**: the order is `UNDELIVERED`, nothing is due (all paid,
 * shipping included, is a refund due, settled by hand), the points used come back (nothing was earned),
 * the coupon's use stays counted. Inventory: `release` first frees what the order still holds (a wired
 * store's lines not yet ticked); then `returned` gets **only the pieces put back whose stock was taken** —
 * every piece of an order placed with no provider, the ticked sizes of one placed wired (as its hold fixed
 * it, whatever the store is now) — so nothing is counted back twice. The order's lock first, Loyalty, then Inventory last. Publishes nothing yet (§6). Audited.
 */
final readonly class MarkCameBackHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_FULFIL;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private LoyaltyApi $loyalty,
        private InventoryApi $inventory,
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|InvalidOrderChange
     */
    public function handle(MarkCameBack $command): void
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);
        $asked = self::putBack($command->putBack);

        $this->db->transaction(function () use ($orderId, $staffId, $asked): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if (! $order->isShipped()) {
                throw new InvalidOrderChange;
            }

            // At most what the parcel held, a size and gift at a time.
            $shipped = [];

            foreach ($this->orders->pieces($order->id) as $piece) {
                $shipped[$piece['variantId'].($piece['gift'] ? ':gift' : '')] = $piece['quantity'];
            }

            foreach ($asked as $key => $line) {
                if ($line->quantity > ($shipped[$key] ?? 0)) {
                    throw new InvalidSalesAttribute('quantity', 'at most what the parcel held');
                }
            }

            // Wired when placed, only what was taken: the sizes ticked "reduced in the provider".
            $taken = $order->wired ? $this->orders->ticked($order->id) : null;
            $back = array_values(array_filter($asked, static fn (HoldLineDto $line): bool => $taken === null || in_array($line->variantId, $taken, true)));

            $now = CarbonImmutable::now();
            $cameBackId = strtolower((string) Str::ulid());
            $this->orders->cameBack($order->id, $cameBackId, $now);
            $this->outbox->record(new OrderCameBack((string) Str::uuid(), $order->id, $order->storeId, $order->customerId, $now));
            $this->changes->record($order, 'CAME_BACK', $staffId, $now, OrderUndoing::status($order, OrderState::UNDELIVERED), ['cameBackId' => $cameBackId, 'pieces' => array_sum(array_map(static fn (HoldLineDto $line): int => $line->quantity, $back))]);
            $this->loyalty->orderCancelled(new SettleOrder($order->id, $order->storeId, new CallerPermission('sales', self::PERMISSION)));
            $this->inventory->release($order->id);

            if ($back !== []) {
                $this->inventory->returned($order->id, $cameBackId, $back);
            }
        }, 3);
    }

    /**
     * The pieces staff put back, a line a size and gift.
     *
     * @param  list<array{variantId: string, quantity: int, gift?: bool}>  $putBack
     * @return array<string, HoldLineDto>
     *
     * @throws InvalidSalesAttribute
     */
    private static function putBack(array $putBack): array
    {
        $lines = [];

        foreach ($putBack as $line) {
            $variantId = strtolower(trim($line['variantId']));
            $gift = $line['gift'] ?? false;

            if (! Ulids::valid($variantId)) {
                throw new InvalidSalesAttribute('variant', 'a size of the parcel');
            }

            if ($line['quantity'] < 1) {
                throw new InvalidSalesAttribute('quantity', 'pieces of the parcel');
            }

            $key = $variantId.($gift ? ':gift' : '');
            $lines[$key] = new HoldLineDto($variantId, ($lines[$key]->quantity ?? 0) + $line['quantity'], $gift);
        }

        return $lines;
    }
}
