<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ReceiveReturn;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Return\ReturnAccess;
use Modules\Sales\Application\Return\ReturnChanges;
use Modules\Sales\Application\Return\ReturnInputs;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Model\ReturnState;
use Modules\Sales\Domain\Repository\Returns;
use Modules\Sales\Public\Events\ReturnReceived;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * **Staff mark an accepted return's pieces received** (sales.md §1.8): for every line of the return, how
 * many came (none to all asked) and how many of those go back into stock — a damaged piece may be left out.
 * Those going back are told to Inventory last (`returned`, once per return): restocked in a store with no
 * provider and for a wired store's stock-dependent sizes and gifts; a wired store's ordinary products
 * follow the provider. The order locked first; in its history and audited.
 */
final readonly class ReceiveReturnHandler
{
    public const string PERMISSION = SalesPermissions::RETURN_MANAGE;

    public function __construct(
        private ReturnAccess $access,
        private Returns $returns,
        private ReturnChanges $changes,
        private InventoryApi $inventory,
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|ReturnNotFound|InvalidOrderChange
     * @throws DomainError Inventory's refusals (`inventory.quantity_invalid` — more back than went out)
     */
    public function handle(ReceiveReturn $command): void
    {
        $counts = ReturnInputs::received($command->lines);
        [$returnId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->returnId);

        $this->db->transaction(function () use ($returnId, $staffId, $counts): void {
            [$order, $return] = $this->changes->lock($returnId, null, [ReturnState::ACCEPTED]);
            $lines = $this->returns->lines($return->id);

            if (array_diff_key($lines, $counts) !== [] || array_diff_key($counts, $lines) !== []) {
                throw new InvalidSalesAttribute('lines', 'every line of the return, once');
            }

            $back = [];

            foreach ($counts as $lineId => [$received, $restocked]) {
                $line = $lines[$lineId];

                if ($received > $line['asked']) {
                    throw new InvalidSalesAttribute('quantity', 'no more than asked');
                }

                if ($restocked > 0) {
                    $key = $line['variantId'].($line['gift'] ? ':gift' : '');
                    $back[$key] = new HoldLineDto($line['variantId'], ($back[$key]->quantity ?? 0) + $restocked, $line['gift']);
                }
            }

            $now = CarbonImmutable::now();
            $this->returns->receive($return->id, $counts, $now);
            $this->outbox->record(new ReturnReceived((string) Str::uuid(), $return->id, $order->id, $order->storeId, $order->customerId, $now));
            $this->changes->record($order, $return, ReturnState::RECEIVED, $staffId, $now, AuditChanges::none()
                ->changed('pieces_received', null, array_sum(array_column($counts, 0)))->changed('pieces_restocked', null, array_sum(array_column($counts, 1))));

            // Inventory last.
            if ($back !== []) {
                $this->inventory->returned($order->id, $return->id, array_values($back));
            }
        }, 3);
    }
}
