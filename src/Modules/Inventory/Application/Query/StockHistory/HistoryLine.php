<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\StockHistory;

/**
 * **One line of a size's history** (inventory.md §4.3.3 `StockHistory`, Q22): when (ISO 8601), the reason
 * (`MovementReason`'s value), before → after and the change (+3 / −2), the note, and **who**:
 *
 * - `STAFF` - a staff member, by name as the reader may see them (`staffName`; `staffHidden` for a Super
 *   Admin read by anyone else, "System administrator" - Platform's `StaffNames`);
 * - `PROVIDER` - **The Provider**;
 * - `FILE` - **A Store File**, with the staff member who switched it on (the requester), named the same way;
 * - `ORDER` - **An Order** (`orderId`; its number once Sales has orders);
 * - `SYSTEM` - **The System**.
 *
 * No link to Sync's read: Inventory does not read Sync.
 */
final readonly class HistoryLine
{
    public const string STAFF = 'STAFF';

    public const string PROVIDER = 'PROVIDER';

    public const string FILE = 'FILE';

    public const string ORDER = 'ORDER';

    public const string SYSTEM = 'SYSTEM';

    public function __construct(
        public string $at,
        public string $reason,
        public int $before,
        public int $after,
        public int $change,
        public ?string $note,
        public string $who,
        public ?string $staffName,
        public bool $staffHidden,
        public ?string $orderId,
    ) {}
}
