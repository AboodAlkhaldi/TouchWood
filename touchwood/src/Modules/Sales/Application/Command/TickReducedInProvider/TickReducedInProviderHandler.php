<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\TickReducedInProvider;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * In a store wired to a provider when the order was placed, staff tick "reduced in the provider" once
 * they have taken a size off the provider's stock by hand (sales.md §1.5; handoff §12.3): for the sizes
 * that counted on stock as the order was placed — its stock-dependent sizes then — and its gift — Inventory's `reducedInProvider` ends their hold lines. Once each; while the order is approved
 * or completed. A ticked line can no longer be edited (§1.7). Inventory last; audited.
 */
final readonly class TickReducedInProviderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_FULFIL;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private InventoryApi $inventory,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|InvalidOrderChange
     */
    public function handle(TickReducedInProvider $command): void
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);
        $asked = array_values(array_unique(array_map(static fn (mixed $id): string => strtolower(trim((string) $id)), $command->variantIds)));

        if ($asked === [] || array_filter($asked, static fn (string $id): bool => ! Ulids::valid($id)) !== []) {
            throw new InvalidSalesAttribute('variant', 'sizes of the order');
        }

        $this->db->transaction(function () use ($orderId, $staffId, $asked): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if (! in_array($order->status, [OrderState::APPROVED, OrderState::COMPLETED], true) || ! $order->wired) {
                throw new InvalidOrderChange;
            }

            $counting = $this->orders->countingOnStock($order->id);

            if (array_diff($asked, $counting) !== []) {
                throw new InvalidSalesAttribute('variant', 'the gift or a size whose stock counted when placed');
            }

            $new = array_values(array_diff($asked, $this->orders->ticked($order->id)));

            if ($new === []) {
                return;
            }

            $now = CarbonImmutable::now();
            $this->orders->tick($order->id, $order->storeId, $new, $staffId, $now);
            $this->changes->record($order, 'TICKED_REDUCED', $staffId, $now, AuditChanges::none()->changed('reduced_in_provider', null, implode(',', $new)), ['variants' => implode(',', $new)]);
            $this->inventory->reducedInProvider($order->id, $new);
        }, 3);
    }
}
