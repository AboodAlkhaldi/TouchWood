<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\MarkDelivered;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\CallerPermission;
use Modules\Loyalty\Public\Dto\EarnPoints;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Public\Events\OrderDelivered;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * Staff mark a shipped order delivered (sales.md §1.5) — customers cannot: it completes, **its return
 * window is fixed to the minute** — delivery plus the store's days now; changing the setting later moves
 * no delivered order (answer 34) — its products become reviewable (`OrderDelivered` through the outbox),
 * and it **earns its points** on its goods total (`LoyaltyApi::earn`, last, after the order's own lock).
 * Audited.
 */
final readonly class MarkDeliveredHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_FULFIL;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private LoyaltyApi $loyalty,
        private PlatformApi $platform,
        private Outbox $outbox,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|OrderNotFound|InvalidOrderChange
     */
    public function handle(MarkDelivered $command): void
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);

        $this->db->transaction(function () use ($orderId, $staffId): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if (! $order->isShipped()) {
                throw new InvalidOrderChange;
            }

            $now = CarbonImmutable::now();
            $days = $this->platform->setting(SalesSettings::RETURN_WINDOW_DAYS, StoreId::fromString($order->storeId))->int();
            $deadline = $now->addDays($days);
            $this->orders->deliver($order->id, $now, $deadline);
            $this->changes->record($order, 'DELIVERED', $staffId, $now, OrderUndoing::status($order, OrderState::COMPLETED)
                ->changed('return_deadline_at', null, $deadline->toAtomString()));
            $this->outbox->record(new OrderDelivered((string) Str::uuid(), $order->id, $order->storeId, $order->customerId, $now));
            $this->loyalty->earn(new EarnPoints(
                $order->id, $order->number, $order->customerId, $order->storeId, Money::of($order->goodsTotal, $order->currencyCode), new CallerPermission('sales', self::PERMISSION),
            ));
        }, 3);
    }
}
