<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\WithdrawReturn;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Return\OwnReturnAccess;
use Modules\Sales\Application\Return\ReturnChanges;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Model\ReturnState;
use Modules\Sales\Domain\Repository\Returns;
use Shared\Application\Unauthorized;

/**
 * **The customer withdraws their return** until staff decide (sales.md §1.8): its pieces may be asked
 * again. The order locked first; in its history.
 */
final readonly class WithdrawReturnHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_MANAGE_OWN;

    public function __construct(
        private OwnReturnAccess $access,
        private Returns $returns,
        private ReturnChanges $changes,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|ReturnNotFound|InvalidOrderChange
     */
    public function handle(WithdrawReturn $command): void
    {
        [$returnId, , $customerId] = $this->access->authorize(self::PERMISSION, $command->returnId);

        $this->db->transaction(function () use ($returnId, $customerId): void {
            [$order, $return] = $this->changes->lock($returnId, $customerId, [ReturnState::REQUESTED]);
            $now = CarbonImmutable::now();
            $this->returns->withdraw($return->id, $now);
            $this->changes->record($order, $return, ReturnState::WITHDRAWN, null, $now);
        }, 3);
    }
}
