<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Listener;

use Illuminate\Database\Connection;
use Modules\Access\Application\Address\StartingAddressFormat;
use Modules\Access\Domain\Repository\StoreAddressFormatRepository;
use Modules\Platform\Public\Events\StoreCreated;

/**
 * A store opened later starts with the same address scheme as the others (amendment 41), so its
 * customers can save an address before staff ever open the format screen. A store that already has
 * a format keeps it: this only ever adds the starting copy.
 */
final readonly class WriteStartingAddressFormat
{
    public function __construct(
        private StoreAddressFormatRepository $formats,
        private Connection $db,
    ) {}

    public function handle(StoreCreated $event): void
    {
        if ($this->formats->existsFor($event->storeId)) {
            return;
        }

        // In one transaction, as every write of a format is: the row and the cache's new version
        // then take effect together (review of step 5).
        $this->db->transaction(fn () => $this->formats->save(StartingAddressFormat::forStore($event->storeId)), 3);
    }
}
