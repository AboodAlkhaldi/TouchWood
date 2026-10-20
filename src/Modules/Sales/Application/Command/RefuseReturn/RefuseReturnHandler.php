<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RefuseReturn;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Order\OrderInputs;
use Modules\Sales\Application\Return\ReturnAccess;
use Modules\Sales\Application\Return\ReturnChanges;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Model\ReturnState;
use Modules\Sales\Domain\Repository\Returns;
use Shared\Application\Unauthorized;

/**
 * **Staff refuse a return** asked for (sales.md §1.8), with words the customer sees (my assumption, stated
 * for the owner to reject) — kept on the return only; the audit says only that they were written
 * (`personal`). The order locked first; in its history.
 */
final readonly class RefuseReturnHandler
{
    public const string PERMISSION = SalesPermissions::RETURN_MANAGE;

    public function __construct(
        private ReturnAccess $access,
        private Returns $returns,
        private ReturnChanges $changes,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|ReturnNotFound|InvalidOrderChange
     */
    public function handle(RefuseReturn $command): void
    {
        $reason = OrderInputs::words($command->reason);
        [$returnId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->returnId);

        $this->db->transaction(function () use ($returnId, $staffId, $reason): void {
            [$order, $return] = $this->changes->lock($returnId, null, [ReturnState::REQUESTED]);
            $now = CarbonImmutable::now();
            $this->returns->decide($return->id, ReturnState::REFUSED, $reason, $staffId, $now);
            $this->changes->record($order, $return, ReturnState::REFUSED, $staffId, $now, AuditChanges::none()->personal('staff_reason'));
        }, 3);
    }
}
