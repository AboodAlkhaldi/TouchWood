<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DiscardImport;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\ImportArchives;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **Discarding a products file not brought in** (catalog.md §1.12; owner, 2026-10-06, amendment
 * 10(b): "file is no longer at our db or sys"): `catalog.import.run`. One deciding, or whose bringing
 * in failed, goes whole — its page, its names and products waiting, and its zip — nothing of it having
 * reached the catalog. One bringing its products in, or brought in, is not (`ImportClosed`): its page
 * is the record of what came from which file. Audited as the file it was.
 */
final readonly class DiscardImportHandler
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
        private ImportArchives $archives,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws ImportClosed|ListItemNotFound|Unauthorized
     */
    public function handle(DiscardImport $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $archive = $this->db->transaction(function () use ($command): ?string {
            // The import's row locked: a confirm or a change waiting on it finds it gone.
            $import = $this->imports->lock($command->importId);

            if ($import === null || $import->kind !== ImportHeader::PRODUCTS) {
                throw new ListItemNotFound($command->importId);
            }

            if (! $import->isDeciding()) {
                throw new ImportClosed;
            }

            $was = ['file_name' => $import->fileName, 'state' => $import->state, 'products' => count($this->imports->products($import->id))];
            $this->imports->discard($import->id);
            $this->platform->recordAudit(ListAudit::changed('import', 'discarded', $import->id, $was, array_fill_keys(array_keys($was), null)) ?? throw new LogicException('No change to record.'));

            return $import->archive;
        }, 3);

        // The rows gone, the zip goes with them (amendment 10(b)).
        if ($archive !== null) {
            $this->archives->forget($archive);
        }
    }
}
