<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Payments\Public\Dto\PaymentDto;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\MoneyRecord;
use Modules\Sales\Domain\Repository\MoneyRecords;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Public\Events\PaymentRecorded;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * An `ONLINE` payment on an order (§9.5 #7): keyed by Payments' own transaction id, so twice is once, and
 * **never refused**: recorded at what the gateway took, whatever the order owes. Any excess shows as a refund
 * due (§1.4). It carries the TEST mark and the mismatch flag. `$tell`: a request's payment sends `PaymentRecorded`
 * (ops.md C10); a checkout's does not, its one email being the order's own (C1).
 */
final readonly class OnlineMoney
{
    public function __construct(
        private Orders $orders,
        private MoneyRecords $records,
        private PlatformApi $platform,
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    /** @throws OrderNotFound */
    public function record(string $orderId, PaymentDto $payment, bool $tell = false): void
    {
        $this->db->transaction(function () use ($orderId, $payment, $tell): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if ($this->records->hasOnline($payment->paymentId)) {
                return;
            }

            $now = CarbonImmutable::now();
            $timezone = $this->platform->store(StoreId::fromString($order->storeId))->timezone ?? 'UTC';
            $id = strtolower((string) Str::ulid());
            $this->records->add(new MoneyRecord(
                MoneyRecord::PAYMENT, $id, $order->id, $order->storeId, $order->currencyCode, $payment->amount->minorUnits, 'ONLINE', $payment->paymentId, null,
                CarbonImmutable::instance($payment->paidAt ?? $now)->setTimezone($timezone), (string) Str::uuid(), null, $now,
                false, $payment->mode === PaymentMode::Test, $payment->mismatch,
            ));
            $this->orders->moveMoney($order->id, $payment->amount->minorUnits, 0);

            if ($tell) {
                $this->outbox->record(new PaymentRecorded((string) Str::uuid(), $id, $order->id, $order->storeId, $order->customerId, $now));
            }

            $this->orders->recordHistory($order->id, $order->storeId, 'PAYMENT_RECORDED', null, null, $now, ['recordId' => $id, 'amount' => $payment->amount->minorUnits, 'way' => 'ONLINE']);
            $this->platform->recordAudit(new AuditEntryDto('sales.payment.recorded', 'sales.payment', $id, $order->storeId, AuditChanges::none()
                ->changed('order_id', null, $order->id)->changed('amount_minor', null, $payment->amount->minorUnits)->changed('way', null, 'ONLINE')));
        }, 3);
    }
}
