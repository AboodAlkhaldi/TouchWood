<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\DismissEntry;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\EntryNotFound;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Repository\ReportEntries;
use Shared\Application\ActorContext;
use Shared\Application\Unauthorized;

/**
 * **Dismiss** an entry, or **Leave it** - an off or draft size left as it is (sync.md §1.6, §3): closed by
 * the person who pressed it, and audited. The same thing seen again opens a new entry. An entry not
 * open is not found.
 */
final readonly class DismissEntryHandler
{
    public const string PERMISSION = SyncPermissions::READ_RUN;

    public function __construct(
        private ReportAccess $access,
        private ReportEntries $entries,
        private ActorContext $actors,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput|EntryNotFound
     */
    public function handle(DismissEntry $command): void
    {
        $entry = $this->entries->findOpen($command->entryId) ?? throw new EntryNotFound;
        $store = $this->access->authorize(self::PERMISSION, $entry['storeId']);

        $this->db->transaction(function () use ($command, $entry, $store): void {
            if (! $this->entries->closeOne($command->entryId, 'STAFF:'.$this->actors->current()->id)) {
                throw new EntryNotFound;
            }

            $this->platform->recordAudit(new AuditEntryDto('sync.entry.dismissed', 'sync.report_entry', (string) $command->entryId, $store->value, AuditChanges::none()
                ->changed('kind', null, $entry['kind']->value)
                ->changed('code', null, $entry['code'])
                ->changed('variant', null, $entry['variantId'])));
        });
    }
}
