<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Public\Events\TransferOverdue;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * `TransferOverdue` (sales.md §9.5 #16): each order whose first receipt is older than its store's
 * `transferCheckHours` and which is still not paid, told **once**. Marked and told in one transaction.
 */
final readonly class TransferOverdueCheck
{
    public const int BATCH = 500;

    public function __construct(
        private Orders $orders,
        private PaymentsApi $payments,
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    public function run(): void
    {
        $now = CarbonImmutable::now();

        foreach ($this->orders->storesWithUncheckedTransfers() as $storeId) {
            $cutoff = $now->subHours($this->payments->transferCheckHours(StoreId::fromString($storeId)));

            do {
                $told = $this->db->transaction(function () use ($storeId, $cutoff, $now): int {
                    $ids = $this->orders->markTransfersOverdue($storeId, $cutoff, $now, self::BATCH);

                    foreach ($ids as $orderId) {
                        $this->outbox->record(new TransferOverdue((string) Str::uuid(), $orderId, $storeId, $now));
                    }

                    return count($ids);
                }, 3);
            } while ($told === self::BATCH);
        }
    }
}
