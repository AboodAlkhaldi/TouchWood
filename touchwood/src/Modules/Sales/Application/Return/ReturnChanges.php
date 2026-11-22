<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Return;

use DateTimeImmutable;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Model\ReturnState;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Domain\Repository\Returns;

/**
 * A return's change (sales.md §1.4, §1.8): its order locked first, then the return, its state one the
 * change starts from; each move in the order's history (`RETURN_` and the state reached) and, by staff,
 * audited.
 */
final readonly class ReturnChanges
{
    public function __construct(
        private Orders $orders,
        private Returns $returns,
        private OrderUndoing $changes,
    ) {}

    /**
     * @param  string|null  $customerId  the customer whose order it must be; null for staff
     * @param  list<string>  $from  the states the change starts from
     * @return array{OrderState, ReturnState}
     *
     * @throws ReturnNotFound|InvalidOrderChange
     */
    public function lock(string $returnId, ?string $customerId, array $from): array
    {
        [$orderId] = $this->returns->orderOf($returnId) ?? throw new ReturnNotFound;
        $order = $this->orders->lock($orderId) ?? throw new ReturnNotFound;

        if ($customerId !== null && $order->customerId !== $customerId) {
            throw new ReturnNotFound;
        }

        $return = $this->returns->lock($returnId) ?? throw new ReturnNotFound;

        return in_array($return->status, $from, true) ? [$order, $return] : throw new InvalidOrderChange;
    }

    /** The return moved to `$to`: in the order's history, and audited when staff moved it. */
    public function record(OrderState $order, ReturnState $return, string $to, ?string $staffId, DateTimeImmutable $at, ?AuditChanges $changes = null): void
    {
        $this->changes->record($order, 'RETURN_'.$to, $staffId, $at, ($changes ?? AuditChanges::none())->changed('return_status', $return->status, $to), ['returnId' => $return->id]);
    }
}
