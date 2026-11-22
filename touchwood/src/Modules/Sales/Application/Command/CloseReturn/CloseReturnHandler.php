<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\CloseReturn;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Return\ReturnAccess;
use Modules\Sales\Application\Return\ReturnChanges;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Model\ReturnState;
use Modules\Sales\Domain\Repository\Returns;
use Shared\Application\Unauthorized;

/**
 * **Staff close an accepted return whose pieces never came** (sales.md §1.8): nothing is refunded, and its
 * pieces may be asked again. The order locked first; in its history and audited.
 */
final readonly class CloseReturnHandler
{
    public const string PERMISSION = SalesPermissions::RETURN_MANAGE;

    public function __construct(
        private ReturnAccess $access,
        private Returns $returns,
        private ReturnChanges $changes,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|ReturnNotFound|InvalidOrderChange
     */
    public function handle(CloseReturn $command): void
    {
        [$returnId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->returnId);

        $this->db->transaction(function () use ($returnId, $staffId): void {
            [$order, $return] = $this->changes->lock($returnId, null, [ReturnState::ACCEPTED]);
            $now = CarbonImmutable::now();
            $this->returns->close($return->id, $now);
            $this->changes->record($order, $return, ReturnState::CLOSED, $staffId, $now);
        }, 3);
    }
}
