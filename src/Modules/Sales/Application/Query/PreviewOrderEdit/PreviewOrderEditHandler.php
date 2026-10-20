<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\PreviewOrderEdit;

use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Command\EditOrder\EditOrderHandler;
use Modules\Sales\Application\Order\EditPlan;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderEditing;
use Modules\Sales\Application\Order\OrderInputs;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\SalesError;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/**
 * **Staff first see an edit worked out** (sales.md §1.7 steps 1–6) — the prices, the coupon and points as
 * it leaves them, the shipping, the totals, the gift's levels and the money difference — then confirm it
 * (`EditOrder`). Reads only; nothing is written.
 */
final readonly class PreviewOrderEditHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_EDIT;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderEditing $editing,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|SalesError|DomainError
     */
    public function handle(PreviewOrderEdit $query): EditPlan
    {
        [$orderId] = $this->access->authorize(self::PERMISSION, $query->orderId);
        $wanted = OrderInputs::lines($query->lines);

        // In a transaction only to read the order as one moment; nothing is written.
        return $this->db->transaction(function () use ($orderId, $wanted, $query): EditPlan {
            $state = $this->orders->lock($orderId) ?? throw new OrderNotFound;
            EditOrderHandler::editable($state, $this->orders->ticked($state->id));

            return $this->editing->plan($this->orders->forEdit($state->id) ?? throw new OrderNotFound, $wanted, $query->addressId, $query->gift);
        });
    }
}
