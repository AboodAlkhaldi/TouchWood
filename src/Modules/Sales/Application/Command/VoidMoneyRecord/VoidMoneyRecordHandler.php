<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\VoidMoneyRecord;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderInputs;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\MoneyRecord;
use Modules\Sales\Domain\Repository\MoneyRecords;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A payment or a refund entered by mistake voided by staff, with a reason (sales.md §1.9): it no longer
 * counts — the order's paid or refunded moves back. Once. **A payment only while what was paid without it
 * still covers what was refunded** (answer 42, refunds being recorded per order); a refund always. The
 * order's lock first, then the record's. In the order's history; audited — the reason as written only.
 * A record of a store the reader does not cover is no record (`OrderNotFound`, as its order).
 */
final readonly class VoidMoneyRecordHandler
{
    public const string PERMISSION = SalesPermissions::PAYMENT_RECORD;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private MoneyRecords $records,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|InvalidOrderChange
     */
    public function handle(VoidMoneyRecord $command): void
    {
        $kind = strtoupper(trim($command->kind));

        if (! in_array($kind, [MoneyRecord::PAYMENT, MoneyRecord::REFUND], true)) {
            throw new InvalidSalesAttribute('way', 'a payment or a refund');
        }

        $recordId = strtolower(trim($command->recordId));
        $orderId = Ulids::valid($recordId) ? $this->records->orderOf($kind, $recordId) : null;
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $orderId ?? '');
        $reason = OrderInputs::words($command->reason);

        $this->db->transaction(function () use ($kind, $recordId, $orderId, $staffId, $reason): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;
            $record = $this->records->lock($kind, $recordId) ?? throw new OrderNotFound;

            if ($record->voided || ($kind === MoneyRecord::PAYMENT && $order->paid - $record->amount < $order->refunded)) {
                throw new InvalidOrderChange;
            }

            $now = CarbonImmutable::now();
            $this->records->void($kind, $record->id, $staffId, $reason, $now);
            $kind === MoneyRecord::PAYMENT ? $this->orders->moveMoney($order->id, -$record->amount, 0) : $this->orders->moveMoney($order->id, 0, -$record->amount);
            $this->orders->recordHistory($order->id, $order->storeId, 'MONEY_VOIDED', null, $staffId, $now, ['recordId' => $record->id, 'kind' => $kind, 'amount' => $record->amount]);
            $this->platform->recordAudit(new AuditEntryDto('sales.'.strtolower($kind).'.voided', 'sales.'.strtolower($kind), $record->id, $order->storeId, AuditChanges::none()
                ->changed('voided', false, true)->personal('void_reason')));
        }, 3);
    }
}
