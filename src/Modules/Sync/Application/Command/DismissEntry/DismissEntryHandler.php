<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\DismissEntry;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\EntryNotDismissable;
use Modules\Sync\Domain\Exception\EntryNotFound;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Unauthorized;

/**
 * **Dismiss** an entry, or **Leave it** - an off or draft size left as it is (sync.md §1.6, §3): closed by
 * the person who pressed it, and audited. The same thing seen again opens a new entry. An entry not
 * open is not found; a failed read's is not dismissed - it closes when a read works again (owner,
 * 2026-10-18). The entry is looked up first, to know its store, as Pricing finds a sale first.
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
     * @throws Unauthorized|InvalidSyncInput|EntryNotFound|EntryNotDismissable
     */
    public function handle(DismissEntry $command): void
    {
        $entry = $this->entries->findOpen($command->entryId) ?? throw new EntryNotFound;
        $store = $this->access->authorize(self::PERMISSION, $entry['storeId']);
        $actor = $this->actors->current();

        // Closed by a person: staff dismiss, never the system.
        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        // A failed read closes only when a read works again (owner, 2026-10-18; sync.md §1.6).
        if ($entry['kind'] === ReportKind::ReadFailed) {
            throw new EntryNotDismissable;
        }

        $this->db->transaction(function () use ($command, $entry, $store, $actor): void {
            if (! $this->entries->closeOne($command->entryId, 'STAFF:'.strtolower((string) $actor->id))) {
                throw new EntryNotFound;
            }

            $changes = AuditChanges::none()->changed('kind', null, $entry['kind']->value);

            if ($entry['code'] !== null) {
                $changes->changed('code', null, $entry['code']);
            }

            if ($entry['variantId'] !== null) {
                $changes->changed('variant', null, $entry['variantId']);
            }

            $this->platform->recordAudit(new AuditEntryDto('sync.entry.dismissed', 'sync.report_entry', (string) $command->entryId, $store->value, $changes));
        });
    }
}
