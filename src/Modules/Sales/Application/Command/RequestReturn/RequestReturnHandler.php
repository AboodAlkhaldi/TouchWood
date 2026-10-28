<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RequestReturn;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\Order\OwnOrderAccess;
use Modules\Sales\Application\Return\ReturnInputs;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\ReturnNotAllowed;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Domain\Repository\Returns;
use Modules\Sales\Public\Events\ReturnRequested;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * **The customer asks to return pieces** of their delivered order (sales.md §1.8): within the return window
 * fixed at delivery, which pieces of which line (a part, or the gift) and how many, a reason from the list
 * or "Other" with their words. A line's pieces are never claimed twice: those asked in a return requested
 * or accepted, and those received in one received or completed, are counted; a refused, withdrawn or
 * closed one counts none. Photos follow (`UploadReturnPhoto`). An off store's delivered orders too (answer
 * 39). The order locked first; in its history.
 */
final readonly class RequestReturnHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_MANAGE_OWN;

    public function __construct(
        private OwnOrderAccess $access,
        private Orders $orders,
        private Returns $returns,
        private OrderUndoing $changes,
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    /**
     * @return string the return's id
     *
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|ReturnNotAllowed
     */
    public function handle(RequestReturn $command): string
    {
        // The request first: whether an id is an order is never told apart by a bad request's answer.
        [$reason, $words] = ReturnInputs::reason($command->reason, $command->other);
        $asked = ReturnInputs::lines($command->lines);
        [$orderId, , $customerId] = $this->access->authorize(self::PERMISSION, $command->orderId);

        return $this->db->transaction(function () use ($orderId, $customerId, $reason, $words, $asked): string {
            $order = $this->orders->lock($orderId);

            if ($order === null || $order->customerId !== $customerId) {
                throw new OrderNotFound;
            }

            $now = CarbonImmutable::now();
            $deadline = $this->returns->deadline($order->id);

            // In whole seconds: the deadline's own second is still in time (times are kept to the second).
            if ($order->status !== OrderState::COMPLETED || $deadline === null || $now->startOfSecond() > $deadline) {
                throw new ReturnNotAllowed;
            }

            $lines = $this->returns->returnable($order->id);

            foreach ($asked as $lineId => $quantity) {
                $line = $lines[$lineId] ?? throw new ReturnNotAllowed;

                if ($quantity > $line['quantity'] - $line['counted']) {
                    throw new ReturnNotAllowed;
                }
            }

            $returnId = strtolower((string) Str::ulid());
            $this->returns->request($returnId, $order->id, $order->storeId, $reason->value, $words, $asked, $now);
            $this->outbox->record(new ReturnRequested((string) Str::uuid(), $returnId, $order->id, $order->storeId, $order->customerId, $now));
            $this->changes->record($order, 'RETURN_REQUESTED', null, $now, AuditChanges::none(), ['returnId' => $returnId, 'reason' => $reason->value]);

            return $returnId;
        }, 3);
    }
}
