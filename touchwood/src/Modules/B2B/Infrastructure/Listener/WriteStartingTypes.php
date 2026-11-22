<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Listener;

use Modules\B2B\Application\Types\GiveEveryStoreTheStartingTypes;
use Modules\Platform\Public\Events\StoreCreated;

/**
 * A store opened after B2B's tables were migrated — the launch stores the seeder creates included —
 * starts with the same company types and document types as every other (b2b.md amendment 6(a)),
 * until its admins change them. A store that already has a list keeps it: this only ever adds.
 */
final readonly class WriteStartingTypes
{
    public function __construct(
        private GiveEveryStoreTheStartingTypes $writer,
    ) {}

    public function handle(StoreCreated $event): void
    {
        $this->writer->forStore($event->storeId);
    }
}
