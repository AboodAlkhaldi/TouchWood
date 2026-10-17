<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\RetryEntry;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\EntryNotFound;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\NotWired;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Shared\Application\Unauthorized;

/**
 * **Retry** on a report entry (sync.md §1.6, §3): its code read again - the code the provider sent, or
 * the size's own today - queued, asked by the person who pressed it; a failed read's entry reads the
 * whole store. The entry closes when the read finds it right. An entry not open is not found.
 */
final readonly class RetryEntryHandler
{
    public const string PERMISSION = SyncPermissions::READ_RUN;

    public function __construct(
        private ReportAccess $access,
        private ReportEntries $entries,
        private Connections $connections,
        private Reads $reads,
        private CatalogApi $catalog,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput|EntryNotFound|NotWired
     */
    public function handle(RetryEntry $command): void
    {
        $entry = $this->entries->findOpen($command->entryId) ?? throw new EntryNotFound;
        $store = $this->access->authorize(self::PERMISSION, $entry['storeId']);

        if (! $this->connections->exists($store)) {
            throw new NotWired;
        }

        $code = $entry['code'] ?? ($entry['variantId'] === null ? null : $this->catalog->variant($entry['variantId'])?->code);

        if ($entry['kind'] === ReportKind::ReadFailed || $code === null) {
            $this->reads->queue($store, ReadKind::Now);

            return;
        }

        $this->reads->queue($store, ReadKind::Code, [$code]);
    }
}
