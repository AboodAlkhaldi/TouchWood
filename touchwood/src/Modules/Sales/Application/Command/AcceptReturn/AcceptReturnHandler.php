<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\AcceptReturn;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Sales\Application\Return\ReturnAccess;
use Modules\Sales\Application\Return\ReturnChanges;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Model\ReturnState;
use Modules\Sales\Domain\Repository\Returns;
use Modules\Sales\Public\Events\ReturnAccepted;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * **Staff accept a return** asked for (sales.md §1.8): the customer sends the pieces back, the company paying
 * the shipping (handoff §12.5). The order locked first; in its history and audited.
 */
final readonly class AcceptReturnHandler
{
    public const string PERMISSION = SalesPermissions::RETURN_MANAGE;

    public function __construct(
        private ReturnAccess $access,
        private Returns $returns,
        private ReturnChanges $changes,
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    /**
     * @throws Unauthorized|ReturnNotFound|InvalidOrderChange
     */
    public function handle(AcceptReturn $command): void
    {
        [$returnId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->returnId);

        $this->db->transaction(function () use ($returnId, $staffId): void {
            [$order, $return] = $this->changes->lock($returnId, null, [ReturnState::REQUESTED]);
            $now = CarbonImmutable::now();
            $this->returns->decide($return->id, ReturnState::ACCEPTED, null, $staffId, $now);
            $this->outbox->record(new ReturnAccepted((string) Str::uuid(), $return->id, $order->id, $order->storeId, $order->customerId, $now));
            $this->changes->record($order, $return, ReturnState::ACCEPTED, $staffId, $now);
        }, 3);
    }
}
