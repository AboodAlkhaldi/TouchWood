<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\StockHistory;

use DateTimeInterface;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\ValueObject\LedgerLine;
use Modules\Inventory\Domain\ValueObject\MovementReason;
use Modules\Platform\Public\Contracts\StaffNames;
use Modules\Platform\Public\Dto\StaffNameDto;
use Shared\Application\Unauthorized;

/**
 * **A size's history** (`StockHistory`, inventory.md §1.6, §4.3.3, Q22), under **Manage Stock** in the
 * store: its ledger only - holds and a wired store's returned extra are kept elsewhere and are not
 * shown - newest first, 50 at a time, each line's staff named in one read of Platform's `StaffNames`
 * (Access binds it), however many. A size with no ledger there has an empty history; an id that is not
 * one, the same. Two queries, and the names. A read: no lock, nothing written.
 */
final readonly class StockHistoryHandler
{
    public const string PERMISSION = InventoryPermissions::STOCK_MANAGE;

    private const string STAFF_ACTOR = 'STAFF';

    public function __construct(
        private InventoryChange $change,
        private StockMovements $movements,
        private StaffNames $staffNames,
    ) {}

    /**
     * @throws Unauthorized|InvalidInventoryInput|StoreOff
     */
    public function handle(StockHistory $query): HistoryPage
    {
        $storeId = $this->change->authorize(self::PERMISSION, $query->storeId)->storeId();
        // The cursor is the last line's id: the ledger's ids rise with every line written.
        $before = $query->after !== null && preg_match('/\A[1-9][0-9]{0,17}\z/', $query->after) === 1 ? (int) $query->after : null;
        $lines = $this->movements->history($storeId, $query->variantId, $before, StockHistory::PER_PAGE + 1);
        $more = count($lines) > StockHistory::PER_PAGE;
        $lines = array_slice($lines, 0, StockHistory::PER_PAGE);
        $names = $this->staffNames->forReader(array_values(array_unique(array_filter(array_map(self::staffOf(...), $lines)))));
        $last = $lines === [] ? null : $lines[count($lines) - 1];

        return new HistoryPage(
            array_map(static function (LedgerLine $line) use ($names): HistoryLine {
                $staff = self::staffOf($line);
                $name = $staff === null ? null : ($names[strtolower($staff)] ?? null);

                return new HistoryLine(
                    $line->at->format(DateTimeInterface::ATOM),
                    $line->reason->value,
                    $line->before,
                    $line->after,
                    $line->after - $line->before,
                    $line->note,
                    self::who($line),
                    $name?->name,
                    $name instanceof StaffNameDto && $name->hidden,
                    $line->orderId,
                );
            }, $lines),
            $more && $last !== null ? (string) $last->id : null,
        );
    }

    /**
     * Who made the line, as the history names it (Q22): the provider's number and a store file by what
     * they are, a shipment and a return by their order, a person by name, anything else the system's.
     */
    private static function who(LedgerLine $line): string
    {
        return match (true) {
            $line->reason === MovementReason::Provider => HistoryLine::PROVIDER,
            $line->reason === MovementReason::File => HistoryLine::FILE,
            in_array($line->reason, [MovementReason::Shipped, MovementReason::Returned], true) => HistoryLine::ORDER,
            $line->actorType === self::STAFF_ACTOR => HistoryLine::STAFF,
            default => HistoryLine::SYSTEM,
        };
    }

    /**
     * The staff member a line names: the person who made it - or, for a store file, the one who switched
     * it on, whose job the system ran (§1.8).
     */
    private static function staffOf(LedgerLine $line): ?string
    {
        return match (self::who($line)) {
            HistoryLine::STAFF => $line->actorId,
            HistoryLine::FILE => $line->requestedByType === self::STAFF_ACTOR ? $line->requestedById : ($line->actorType === self::STAFF_ACTOR ? $line->actorId : null),
            default => null,
        };
    }
}
