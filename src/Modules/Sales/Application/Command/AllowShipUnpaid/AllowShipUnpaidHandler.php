<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\AllowShipUnpaid;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;

/**
 * Whether an approved order may ship before what it holds covers what is due (sales.md §1.4): decided by
 * staff per order (owner, 2026-10-10) — an explicit, audited switch, on or off, until it ships. Setting
 * it as it is changes nothing.
 */
final readonly class AllowShipUnpaidHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_FULFIL;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|OrderNotFound|InvalidOrderChange
     */
    public function handle(AllowShipUnpaid $command): void
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);

        $this->db->transaction(function () use ($orderId, $staffId, $command): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if (! $order->isApproved() || ! $order->isCancellable()) {
                throw new InvalidOrderChange;
            }

            if ($order->mayShipUnpaid === $command->allow) {
                return;
            }

            $this->orders->allowShipUnpaid($order->id, $command->allow);
            $this->changes->record($order, 'SHIP_UNPAID_ALLOWED', $staffId, CarbonImmutable::now(), AuditChanges::none()->changed('may_ship_unpaid', $order->mayShipUnpaid, $command->allow), ['allow' => $command->allow]);
        }, 3);
    }
}
