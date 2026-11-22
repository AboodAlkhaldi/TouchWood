<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\CompleteReturn;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\CallerPermission;
use Modules\Loyalty\Public\Dto\SettleReturn;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Return\ReturnAccess;
use Modules\Sales\Application\Return\ReturnChanges;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Model\ReturnState;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Domain\Repository\Returns;
use Modules\Sales\Domain\Service\ReturnRefunds;
use Modules\Sales\Public\Events\ReturnCompleted;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\Money;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * **Staff complete a received return** (sales.md §1.8): it refunds what came — each piece its goods share
 * plus its VAT share, by running totals (`ReturnRefunds`); a gift's nothing; the shipping (no VAT on it, §9.5 #1)
 * with the return that makes every bought piece returned. The refund comes off what the order is due, so
 * the money it holds above that is a refund staff record by hand (handoff §12.4). Loyalty settles the
 * points used and earned on the pieces' goods and points shares (`orderReturned`, once per return), and
 * `ReturnCompleted` goes to the outbox — Feedback flips its reviews. The order locked first; in its
 * history and audited.
 */
final readonly class CompleteReturnHandler
{
    public const string PERMISSION = SalesPermissions::RETURN_MANAGE;

    public function __construct(
        private ReturnAccess $access,
        private Orders $orders,
        private Returns $returns,
        private ReturnChanges $changes,
        private LoyaltyApi $loyalty,
        private Outbox $outbox,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return int the refund the return owes, in the order's currency's smallest unit
     *
     * @throws Unauthorized|ReturnNotFound|InvalidOrderChange
     * @throws DomainError Loyalty's refusals
     */
    public function handle(CompleteReturn $command): int
    {
        [$returnId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->returnId);

        return $this->db->transaction(function () use ($returnId, $staffId): int {
            [$order, $return] = $this->changes->lock($returnId, null, [ReturnState::RECEIVED]);
            $facts = $this->returns->forRefund($order->id);
            $received = array_map(static fn (array $line): int => $line['received'] ?? 0, $this->returns->lines($return->id));
            $refund = ReturnRefunds::of($facts['lines'], $facts['before'], $received, $facts['shippingRefunded'], $facts['shippingFee']);

            $now = CarbonImmutable::now();
            $this->returns->complete($return->id, $refund['refund'], $refund['shipping'], $now);
            $this->orders->lowerDue($order->id, $refund['refund']);
            $this->changes->record($order, $return, ReturnState::COMPLETED, $staffId, $now, AuditChanges::none()
                ->changed('refund_due_minor', null, $refund['refund'])->changed('due_minor', $order->due, $order->due - $refund['refund']));
            $this->loyalty->orderReturned(new SettleReturn(
                $order->id, $order->storeId, $return->id, Money::of($refund['goods'], $order->currencyCode), Money::of($refund['points'], $order->currencyCode),
                new CallerPermission('sales', self::PERMISSION),
            ));
            $this->outbox->record(new ReturnCompleted((string) Str::uuid(), $return->id, $order->id, $order->storeId, $order->customerId, $now));

            return $refund['refund'];
        }, 3);
    }
}
